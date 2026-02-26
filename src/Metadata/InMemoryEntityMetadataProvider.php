<?php

declare(strict_types=1);

namespace SyncForge\Metadata;

use SyncForge\Exception\MetadataException;

final class InMemoryEntityMetadataProvider implements EntityMetadataProviderInterface
{
    /** @var array<string, EntityMetadata> */
    private array $metadataByClass;

    /**
     * @param array<string, EntityMetadata> $metadataByClass
     */
    public function __construct(array $metadataByClass)
    {
        $this->metadataByClass = $metadataByClass;
    }

    public function get(string $entityClass): EntityMetadata
    {
        if (!isset($this->metadataByClass[$entityClass])) {
            throw new MetadataException(sprintf('Metadata for "%s" is not configured.', $entityClass));
        }

        return $this->metadataByClass[$entityClass];
    }
}
