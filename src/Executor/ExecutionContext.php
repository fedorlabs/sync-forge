<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use SyncForge\Metadata\EntityMetadata;

final class ExecutionContext
{
    /**
     * @param list<string> $keyFields
     */
    public function __construct(
        public readonly EntityMetadata $metadata,
        public readonly array $keyFields,
        public readonly bool $dryRun,
        public readonly ?int $chunkIndex,
    ) {
    }
}
