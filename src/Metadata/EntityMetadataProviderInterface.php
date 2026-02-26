<?php

declare(strict_types=1);

namespace SyncForge\Metadata;

interface EntityMetadataProviderInterface
{
    public function get(string $entityClass): EntityMetadata;
}
