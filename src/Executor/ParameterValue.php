<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use ReflectionClass;

final class ParameterValue
{
    private const DATE_TIME_TYPES = [
        Types::DATE_MUTABLE,
        Types::DATE_IMMUTABLE,
        Types::TIME_MUTABLE,
        Types::TIME_IMMUTABLE,
        Types::DATETIME_MUTABLE,
        Types::DATETIME_IMMUTABLE,
        Types::DATETIMETZ_MUTABLE,
        Types::DATETIMETZ_IMMUTABLE,
    ];

    /**
     * Converts a PHP value to its database representation the way Doctrine ORM does, through the DBAL type of the
     * column and without shifting dates to another timezone. Scalars and null are written as they are, so values
     * that already are in database form (for example a JSON string) are not converted twice.
     */
    public static function normalize(mixed $value, AbstractPlatform $platform, ?string $dbalType = null): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            $typeName = self::dateTimeTypeName($dbalType);

            return Type::getType($typeName)->convertToDatabaseValue(self::matchDateTimeClass($value, $typeName), $platform);
        }

        if ((is_object($value) || is_array($value)) && $dbalType !== null && Type::hasType($dbalType)) {
            return Type::getType($dbalType)->convertToDatabaseValue($value, $platform);
        }

        return $value;
    }

    /**
     * DBAL 4 date types accept only their own class: immutable types require DateTimeImmutable and mutable
     * types require DateTime. Built-in types get the matching class; custom types receive the value untouched.
     */
    private static function matchDateTimeClass(DateTimeInterface $value, string $typeName): DateTimeInterface
    {
        if (!in_array($typeName, self::DATE_TIME_TYPES, true)) {
            return $value;
        }

        return str_ends_with($typeName, '_immutable')
            ? DateTimeImmutable::createFromInterface($value)
            : DateTime::createFromInterface($value);
    }

    private static function dateTimeTypeName(?string $dbalType): string
    {
        if ($dbalType === null || !Type::hasType($dbalType)) {
            return Types::DATETIME_IMMUTABLE;
        }

        if (in_array($dbalType, self::DATE_TIME_TYPES, true)) {
            return $dbalType;
        }

        // A custom type (not one of the built-in DBAL types) owns the conversion of its own values.
        return in_array($dbalType, (new ReflectionClass(Types::class))->getConstants(), true)
            ? Types::DATETIME_IMMUTABLE
            : $dbalType;
    }
}
