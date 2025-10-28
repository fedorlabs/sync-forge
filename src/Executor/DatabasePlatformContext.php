<?php

declare(strict_types=1);

namespace SyncForge\Executor;

final class DatabasePlatformContext
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
