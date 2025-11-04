<?php

declare(strict_types=1);

namespace SyncForge\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use SyncForge\Executor\DatabasePlatformContext;
use SyncForge\Executor\PlatformDetectorInterface;

final class DbalPlatformDetector implements PlatformDetectorInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function detect(): DatabasePlatformContext
    {
        $platformClass = strtolower($this->connection->getDatabasePlatform()::class);

        $name = match (true) {
            str_contains($platformClass, 'postgres') => 'postgresql',
            str_contains($platformClass, 'mariadb') => 'mariadb',
            str_contains($platformClass, 'mysql') => 'mysql',
            str_contains($platformClass, 'sqlite') => 'sqlite',
            default => 'fallback',
        };

        return new DatabasePlatformContext($name);
    }
}
