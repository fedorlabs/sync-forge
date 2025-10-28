<?php

declare(strict_types=1);

namespace SyncForge\Executor;

interface PlatformDetectorInterface
{
    public function detect(): DatabasePlatformContext;
}
