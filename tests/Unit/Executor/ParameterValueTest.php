<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\Executor;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;
use SyncForge\Executor\ParameterValue;
use SyncForge\Tests\Support\Money;
use SyncForge\Tests\Support\MoneyType;
use SyncForge\Tests\Support\StockStatus;

final class ParameterValueTest extends TestCase
{
    protected function setUp(): void
    {
        MoneyType::register();
    }

    public function testScalarsAndNullAreWrittenAsTheyAre(): void
    {
        $platform = new SQLitePlatform();

        self::assertSame('{"a":1}', ParameterValue::normalize('{"a":1}', $platform, 'json'));
        self::assertSame(5, ParameterValue::normalize(5, $platform, MoneyType::NAME));
        self::assertNull(ParameterValue::normalize(null, $platform, 'json'));
    }

    public function testArrayIsConvertedThroughJsonType(): void
    {
        self::assertSame('{"a":1}', ParameterValue::normalize(['a' => 1], new SQLitePlatform(), 'json'));
    }

    public function testObjectIsConvertedThroughCustomType(): void
    {
        self::assertSame(1050, ParameterValue::normalize(new Money(1050), new SQLitePlatform(), MoneyType::NAME));
    }

    public function testBackedEnumIsWrittenAsItsValue(): void
    {
        self::assertSame('sold_out', ParameterValue::normalize(StockStatus::SoldOut, new SQLitePlatform(), null));
    }

    public function testObjectWithoutKnownTypeIsLeftUntouched(): void
    {
        $money = new Money(1);

        self::assertSame($money, ParameterValue::normalize($money, new SQLitePlatform(), null));
        self::assertSame($money, ParameterValue::normalize($money, new SQLitePlatform(), 'unknown_type'));
    }

    public function testMutableAndImmutableDateTimesMatchTheClassTheColumnTypeAccepts(): void
    {
        $platform = new SQLitePlatform();
        $mutable = new DateTime('2024-06-01 13:45:00');
        $immutable = new DateTimeImmutable('2024-06-01 13:45:00');

        foreach (['datetime_immutable', 'datetimetz_immutable', 'date_immutable', 'time_immutable', 'datetime', 'datetimetz', 'date', 'time'] as $type) {
            self::assertSame(
                ParameterValue::normalize($immutable, $platform, $type),
                ParameterValue::normalize($mutable, $platform, $type),
                $type,
            );
        }

        self::assertSame('2024-06-01 13:45:00', ParameterValue::normalize($mutable, $platform, 'datetime_immutable'));
        self::assertSame('2024-06-01 13:45:00', ParameterValue::normalize($immutable, $platform, 'datetime'));
    }

    public function testDateTimeUsesDateTypeOfTheColumn(): void
    {
        $date = new DateTimeImmutable('2024-06-01 13:45:00');

        self::assertSame('2024-06-01', ParameterValue::normalize($date, new SQLitePlatform(), 'date_immutable'));
        self::assertSame('2024-06-01 13:45:00', ParameterValue::normalize($date, new SQLitePlatform(), 'string'));
    }
}
