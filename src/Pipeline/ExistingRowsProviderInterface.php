<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use SyncForge\Metadata\EntityMetadata;

interface ExistingRowsProviderInterface
{
    /**
     * @param list<string> $keyFields
     * @param list<array<string,mixed>> $incomingRows
     * @return list<array<string,mixed>>
     */
    public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array;

    /**
     * @param list<string> $keyFields
     * @return list<array<string,mixed>>
     */
    public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array;
}
