<?php

declare(strict_types=1);

namespace SyncForge\Bridge\DoctrineOrm;

use SyncForge\Exception\MetadataException;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Metadata\EntityMetadataProviderInterface;

final class DoctrineOrmEntityMetadataProvider implements EntityMetadataProviderInterface
{
    /** @var array<string, EntityMetadata> */
    private array $cache = [];

    public function __construct(
        private readonly object $entityManager,
    ) {
        if (!method_exists($this->entityManager, 'getClassMetadata')) {
            throw new MetadataException(
                'DoctrineOrmEntityMetadataProvider expects an entity manager-like object with getClassMetadata().',
            );
        }
    }

    public function get(string $entityClass): EntityMetadata
    {
        if (isset($this->cache[$entityClass])) {
            return $this->cache[$entityClass];
        }

        $doctrine = $this->loadClassMetadata($entityClass);
        if (!is_object($doctrine)) {
            throw new MetadataException(sprintf('Doctrine metadata not found for "%s".', $entityClass));
        }

        $fields = [];
        $fieldToColumn = [];

        /** @var array<string,mixed> $fieldMappings */
        $fieldMappings = is_array($doctrine->fieldMappings ?? null) ? $doctrine->fieldMappings : [];
        foreach ($fieldMappings as $field => $mapping) {
            $columnName = is_object($mapping) && is_string($mapping->columnName ?? null)
                ? $mapping->columnName
                : (is_array($mapping) && is_string($mapping['columnName'] ?? null) ? $mapping['columnName'] : null);

            if ($columnName === null || $columnName === '') {
                continue;
            }

            $fields[] = $field;
            $fieldToColumn[$field] = $columnName;
        }

        /** @var array<string,mixed> $associationMappings */
        $associationMappings = is_array($doctrine->associationMappings ?? null) ? $doctrine->associationMappings : [];
        foreach ($associationMappings as $field => $mapping) {
            $isToOneOwningSide = is_object($mapping) && method_exists($mapping, 'isToOneOwningSide')
                ? (bool) $mapping->isToOneOwningSide()
                : (is_array($mapping) && isset($mapping['isOwningSide'], $mapping['type'])
                    ? (bool) $mapping['isOwningSide'] && in_array((int) $mapping['type'], [1, 2], true)
                    : false);

            if (!$isToOneOwningSide) {
                continue;
            }

            $joinColumns = is_object($mapping)
                ? (is_array($mapping->joinColumns ?? null) ? $mapping->joinColumns : [])
                : (is_array($mapping) && is_array($mapping['joinColumns'] ?? null) ? $mapping['joinColumns'] : []);

            if (count($joinColumns) !== 1) {
                continue;
            }

            $joinColumn = $joinColumns[0] ?? null;
            $columnName = is_object($joinColumn) && is_string($joinColumn->name ?? null)
                ? $joinColumn->name
                : (is_array($joinColumn) && is_string($joinColumn['name'] ?? null) ? $joinColumn['name'] : null);

            if (!is_string($columnName) || $columnName === '') {
                continue;
            }

            $fields[] = $field;
            $fieldToColumn[$field] = $columnName;
        }

        if ($fields === []) {
            throw new MetadataException(sprintf('No scalar columns resolved for "%s".', $entityClass));
        }

        $identifierFields = [];
        /** @var array<int,string> $identifiers */
        $identifiers = is_array($doctrine->identifier ?? null) ? $doctrine->identifier : [];
        foreach ($identifiers as $idField) {
            if (isset($fieldToColumn[$idField])) {
                $identifierFields[] = $idField;
            }
        }

        $updatableFields = array_values(array_filter(
            $fields,
            static fn (string $field): bool => !in_array($field, $identifierFields, true),
        ));

        if (!method_exists($doctrine, 'getTableName')) {
            throw new MetadataException(sprintf('Doctrine metadata for "%s" does not expose getTableName().', $entityClass));
        }

        $tableName = (string) $doctrine->getTableName();

        $metadata = new EntityMetadata(
            entityClass: $entityClass,
            tableName: $tableName,
            fields: array_values(array_unique($fields)),
            fieldToColumn: $fieldToColumn,
            identifierFields: $identifierFields,
            updatableFields: $updatableFields,
        );

        $this->cache[$entityClass] = $metadata;

        return $metadata;
    }

    private function loadClassMetadata(string $entityClass): mixed
    {
        $callable = [$this->entityManager, 'getClassMetadata'];
        if (!is_callable($callable)) {
            throw new MetadataException('Entity manager does not provide a callable getClassMetadata method.');
        }

        return \Closure::fromCallable($callable)($entityClass);
    }
}
