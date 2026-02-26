<?php

declare(strict_types=1);

namespace SyncForge\Executor;

final class StaticPlatformDetector implements PlatformDetectorInterface
{
    public function __construct(
        private readonly DatabasePlatformContext $platform,
    ) {
    }

    public function detect(): DatabasePlatformContext
    {
        return $this->platform;
    }
}
