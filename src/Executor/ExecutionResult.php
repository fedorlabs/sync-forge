<?php

declare(strict_types=1);

namespace SyncForge\Executor;

final class ExecutionResult
{
    public function __construct(
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $deleted,
    ) {
    }
}
