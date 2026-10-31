<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use DateTimeInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

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
     * Converts DateTimeInterface values the way Doctrine ORM does: through the DBAL type of the column,
     * without shifting the value to another timezone. Other values are returned unchanged.
     */
    public static function normalize(mixed $value, AbstractPlatform $platform, ?string $dbalType = null): mixed
    {
        if (!$value instanceof DateTimeInterface) {
            return $value;
        }

        $typeName = $dbalType !== null && in_array($dbalType, self::DATE_TIME_TYPES, true)
            ? $dbalType
            : Types::DATETIME_IMMUTABLE;

        return Type::getType($typeName)->convertToDatabaseValue($value, $platform);
    }
}
