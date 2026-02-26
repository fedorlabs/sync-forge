<?php

declare(strict_types=1);

namespace SyncForge\Metadata;

use SyncForge\Exception\MetadataException;

final class EntityMetadata
{
    /**
     * @param list<string> $fields
     * @param array<string,string> $fieldToColumn
     * @param list<string> $identifierFields
     * @param list<string> $updatableFields
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly string $tableName,
        public readonly array $fields,
        public readonly array $fieldToColumn,
        public readonly array $identifierFields,
        public readonly array $updatableFields,
    ) {
        if ($fields === []) {
            throw new MetadataException('EntityMetadata fields cannot be empty.');
        }

        foreach ($fieldToColumn as $field => $column) {
            if ($field === '' || $column === '') {
                throw new MetadataException('Field and column names must be non-empty.');
            }
        }
    }
}
