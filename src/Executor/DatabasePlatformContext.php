<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use Doctrine\DBAL\Platforms\AbstractPlatform;

final class DatabasePlatformContext
{
    public function __construct(
        public readonly string $name,
        public readonly ?AbstractPlatform $platform = null,
    ) {
    }
}
