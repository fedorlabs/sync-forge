<?php

declare(strict_types=1);

namespace SyncForge\Metadata;

use Closure;
use SyncForge\Exception\MetadataException;

final class DoctrineEntityMetadataProvider implements EntityMetadataProviderInterface
{
    /**
     * Adapter callable must return an array with keys:
     * tableName, fields, fieldToColumn, identifierFields, updatableFields
     *
     * @var Closure(string):array<string,mixed>
     */
    private readonly Closure $metadataLoader;

    /** @var array<string, EntityMetadata> */
    private array $cache = [];

    /**
     * @param callable(string):array<string,mixed> $metadataLoader
     */
    public function __construct(callable $metadataLoader)
    {
        $this->metadataLoader = $metadataLoader(...);
    }

    public function get(string $entityClass): EntityMetadata
    {
        if (isset($this->cache[$entityClass])) {
            return $this->cache[$entityClass];
        }

        $raw = ($this->metadataLoader)($entityClass);

        foreach (['tableName', 'fields', 'fieldToColumn', 'identifierFields', 'updatableFields'] as $required) {
            if (!array_key_exists($required, $raw)) {
                throw new MetadataException(sprintf('Doctrine metadata loader result missing key "%s".', $required));
            }
        }

        $metadata = new EntityMetadata(
            entityClass: $entityClass,
            tableName: (string) $raw['tableName'],
            fields: array_values($raw['fields']),
            fieldToColumn: $raw['fieldToColumn'],
            identifierFields: array_values($raw['identifierFields']),
            updatableFields: array_values($raw['updatableFields']),
        );

        $this->cache[$entityClass] = $metadata;

        return $metadata;
    }
}
