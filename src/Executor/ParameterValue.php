<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class ParameterValue
{
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
        }

        return $value;
    }
}
