<?php

declare(strict_types=1);

namespace SyncForge\Tests\Support;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class MoneyType extends Type
{
    public const NAME = 'test_money';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        return $value instanceof Money ? $value->cents : null;
    }

    public static function register(): void
    {
        if (!Type::hasType(self::NAME)) {
            Type::addType(self::NAME, self::class);
        }
    }
}
