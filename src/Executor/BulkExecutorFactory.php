<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use SyncForge\Exception\UnsupportedPlatformException;

final class BulkExecutorFactory
{
    /** @var list<BulkExecutorInterface> */
    private array $executors;

    /**
     * @param list<BulkExecutorInterface> $executors
     */
    public function __construct(array $executors)
    {
        $this->executors = $executors;
    }

    public function forPlatform(DatabasePlatformContext $platform): BulkExecutorInterface
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($platform)) {
                return $executor;
            }
        }

        throw new UnsupportedPlatformException(sprintf('No bulk executor for platform "%s".', $platform->name));
    }
}
