<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use SyncForge\Metadata\EntityMetadata;

final class NullExistingRowsProvider implements ExistingRowsProviderInterface
{
    public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
    {
        return [];
    }

    public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
    {
        return [];
    }
}
