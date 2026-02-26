<?php

declare(strict_types=1);

namespace SyncForge\Diff;

use SyncForge\Metadata\EntityMetadata;

final class DiffContext
{
    /**
     * @param list<string> $keyFields
     */
    public function __construct(
        public readonly EntityMetadata $metadata,
        public readonly array $keyFields,
    ) {
    }
}
