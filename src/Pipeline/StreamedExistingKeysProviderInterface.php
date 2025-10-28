<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use SyncForge\Metadata\EntityMetadata;

interface StreamedExistingKeysProviderInterface
{
    /**
     * @param list<string> $keyFields
     * @return iterable<list<array<string,mixed>>>
     */
    public function iterateAllKeys(EntityMetadata $metadata, array $keyFields, int $batchSize = 1000): iterable;
}
