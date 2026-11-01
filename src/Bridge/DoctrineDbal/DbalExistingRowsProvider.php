<?php

declare(strict_types=1);

namespace SyncForge\Bridge\DoctrineDbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use SyncForge\Exception\MetadataException;
use SyncForge\Exception\SyncExecutionException;
use SyncForge\Key\KeyResolverInterface;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Pipeline\ScopedExistingRowsProviderInterface;

final class DbalExistingRowsProvider implements ScopedExistingRowsProviderInterface
{
    private const COMPOSITE_KEY_BATCH_SIZE = 250;
    private const KEY_SCAN_BATCH_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly KeyResolverInterface $keyResolver,
    ) {
    }

    /**
     * @throws Exception
     */
    public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
    {
        return $this->fetchRows($metadata, $keyFields, $incomingRows, []);
    }

    /**
     * @throws Exception
     */
    public function fetchByIncomingRowsInScope(EntityMetadata $metadata, array $keyFields, array $incomingRows, array $scope): array
    {
        return $this->fetchRows($metadata, $keyFields, $incomingRows, $scope);
    }

    /**
     * @param list<string> $keyFields
     * @param list<array<string,mixed>> $incomingRows
     * @param array<string,scalar|null> $scope
     * @return list<array<string,mixed>>
     * @throws Exception
     */
    private function fetchRows(EntityMetadata $metadata, array $keyFields, array $incomingRows, array $scope): array
    {
        if ($incomingRows === []) {
            return [];
        }

        $indexed = $this->keyResolver->indexByKey($incomingRows, $keyFields);
        if ($indexed->rowsByKey === []) {
            return [];
        }

        if (count($keyFields) === 1) {
            return $this->fetchBySingleKey($metadata, $indexed->rowsByKey, $keyFields[0], $scope);
        }

        return $this->fetchByCompositeKey($metadata, $keyFields, array_values($indexed->rowsByKey), $scope);
    }

    /**
     * @param array<string,array<string,mixed>> $rowsByKey
     * @param array<string,scalar|null> $scope
     * @return list<array<string,mixed>>
     * @throws Exception
     */
    private function fetchBySingleKey(EntityMetadata $metadata, array $rowsByKey, string $keyField, array $scope): array
    {
        $keyColumn = $metadata->fieldToColumn[$keyField] ?? null;
        if ($keyColumn === null) {
            throw new MetadataException(sprintf('Key field "%s" has no column mapping.', $keyField));
        }

        $keyValues = [];
        $hasNull = false;

        foreach ($rowsByKey as $row) {
            $value = $row[$keyField] ?? null;
            if ($value === null) {
                $hasNull = true;
                continue;
            }

            $keyValues[] = $value;
        }

        $qb = $this->createSelectQueryBuilder($metadata);
        $conditions = [];
        $quotedKeyColumn = $this->quoteIdentifier($keyColumn);

        if ($keyValues !== []) {
            $arrayType = $this->detectArrayType($keyValues);
            $conditions[] = sprintf('%s IN (:keys)', $quotedKeyColumn);
            $qb->setParameter('keys', $this->normalizeArrayParameterValues($keyValues, $arrayType), $arrayType);
        }

        if ($hasNull) {
            $conditions[] = sprintf('%s IS NULL', $quotedKeyColumn);
        }

        if ($conditions === []) {
            return [];
        }

        $qb->where(implode(' OR ', $conditions));
        $this->applyScope($qb, $metadata, $scope);
        $rawRows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(fn (array $rawRow): array => $this->mapColumnRowToFieldRow($metadata, $rawRow), $rawRows);
    }

    /**
     * @param list<string> $keyFields
     * @param list<array<string,mixed>> $rows
     * @param array<string,scalar|null> $scope
     * @return list<array<string,mixed>>
     * @throws Exception
     */
    private function fetchByCompositeKey(EntityMetadata $metadata, array $keyFields, array $rows, array $scope): array
    {
        $allRawRows = [];

        foreach (array_chunk($rows, self::COMPOSITE_KEY_BATCH_SIZE) as $batch) {
            $qb = $this->createSelectQueryBuilder($metadata);
            $rowConditions = [];

            foreach ($batch as $rowNum => $row) {
                $parts = [];
                foreach ($keyFields as $keyField) {
                    $column = $metadata->fieldToColumn[$keyField] ?? null;
                    if ($column === null) {
                        throw new MetadataException(sprintf('Key field "%s" has no column mapping.', $keyField));
                    }

                    $value = $row[$keyField] ?? null;
                    if ($value === null) {
                        $parts[] = sprintf('%s IS NULL', $this->quoteIdentifier($column));
                        continue;
                    }

                    $param = sprintf('k_%d_%s', $rowNum, $keyField);
                    $parts[] = sprintf('%s = :%s', $this->quoteIdentifier($column), $param);
                    $qb->setParameter($param, $value, $this->detectType($value));
                }

                $rowConditions[] = '(' . implode(' AND ', $parts) . ')';
            }

            $qb->where(implode(' OR ', $rowConditions));
            $this->applyScope($qb, $metadata, $scope);
            $allRawRows = [...$allRawRows, ...$qb->executeQuery()->fetchAllAssociative()];
        }

        return array_map(fn (array $rawRow): array => $this->mapColumnRowToFieldRow($metadata, $rawRow), $allRawRows);
    }

    /**
     * @throws Exception
     */
    private function createSelectQueryBuilder(EntityMetadata $metadata): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $selectColumns = [];

        foreach ($metadata->fields as $field) {
            $column = $metadata->fieldToColumn[$field] ?? null;
            if ($column === null) {
                throw new MetadataException(sprintf('Field "%s" has no column mapping.', $field));
            }

            $selectColumns[] = $this->quoteIdentifier($column);
        }

        return $qb
            ->select(...$selectColumns)
            ->from($this->quoteIdentifier($metadata->tableName));
    }

    /**
     * @throws Exception
     */
    public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
    {
        $allRows = [];

        foreach ($this->iterateAllKeys($metadata, $keyFields, self::KEY_SCAN_BATCH_SIZE) as $batchRows) {
            $allRows = [...$allRows, ...$batchRows];
        }

        return $allRows;
    }

    public function iterateAllKeys(EntityMetadata $metadata, array $keyFields, int $batchSize = self::KEY_SCAN_BATCH_SIZE): iterable
    {
        return $this->iterateAllKeysInScope($metadata, $keyFields, [], $batchSize);
    }

    public function iterateAllKeysInScope(EntityMetadata $metadata, array $keyFields, array $scope, int $batchSize = self::KEY_SCAN_BATCH_SIZE): iterable
    {
        if ($keyFields === []) {
            return;
        }

        $columns = [];
        foreach ($keyFields as $keyField) {
            $columns[] = $metadata->fieldToColumn[$keyField] ?? $keyField;
        }

        $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $limit = max(1, $batchSize);
        $lastRow = null;

        while (true) {
            $qb = $this->connection->createQueryBuilder();
            $qb->select(...$quotedColumns)
                ->from($this->quoteIdentifier($metadata->tableName))
                ->setMaxResults($limit);

            foreach ($quotedColumns as $quotedColumn) {
                $qb->addOrderBy($quotedColumn, 'ASC');
            }

            if ($lastRow !== null) {
                $qb->where($this->buildKeysetCondition($qb, $columns, $quotedColumns, $lastRow));
            }

            $this->applyScope($qb, $metadata, $scope);

            $rawRows = $qb->executeQuery()->fetchAllAssociative();
            if ($rawRows === []) {
                return;
            }

            $lastRow = $rawRows[array_key_last($rawRows)];

            yield array_map(fn (array $rawRow): array => $this->mapColumnRowToFieldRow($metadata, $rawRow), $rawRows);

            if (count($rawRows) < $limit) {
                return;
            }
        }
    }

    /**
     * Builds "(c1 > :v1) OR (c1 = :v1 AND c2 > :v2) ..." so each page starts after the last key
     * of the previous one and stays correct while earlier rows are being deleted.
     *
     * @param list<string> $columns
     * @param list<string> $quotedColumns
     * @param array<string,mixed> $lastRow
     * @throws SyncExecutionException
     */
    private function buildKeysetCondition(QueryBuilder $qb, array $columns, array $quotedColumns, array $lastRow): string
    {
        $alternatives = [];

        foreach ($columns as $index => $column) {
            $value = $lastRow[$column] ?? null;
            if ($value === null) {
                throw new SyncExecutionException(sprintf('Key scan cannot paginate over NULL in key column "%s".', $column));
            }

            $param = sprintf('last_%d', $index);
            $qb->setParameter($param, $value, $this->detectType($value));

            $parts = [];
            for ($prev = 0; $prev < $index; $prev++) {
                $parts[] = sprintf('%s = :last_%d', $quotedColumns[$prev], $prev);
            }
            $parts[] = sprintf('%s > :%s', $quotedColumns[$index], $param);

            $alternatives[] = '(' . implode(' AND ', $parts) . ')';
        }

        return implode(' OR ', $alternatives);
    }

    /**
     * @param array<string,scalar|null> $scope
     * @throws MetadataException
     */
    private function applyScope(QueryBuilder $qb, EntityMetadata $metadata, array $scope): void
    {
        $index = 0;

        foreach ($scope as $field => $value) {
            $column = $metadata->fieldToColumn[$field] ?? null;
            if ($column === null) {
                throw new MetadataException(sprintf('Scope field "%s" has no column mapping.', $field));
            }

            $quotedColumn = $this->quoteIdentifier($column);
            if ($value === null) {
                $qb->andWhere(sprintf('%s IS NULL', $quotedColumn));
                continue;
            }

            $param = sprintf('scope_%d', $index++);
            $qb->andWhere(sprintf('%s = :%s', $quotedColumn, $param));
            $qb->setParameter($param, $value, $this->detectType($value));
        }
    }

    /**
     * @param array<string,mixed> $rawRow
     * @return array<string,mixed>
     */
    private function mapColumnRowToFieldRow(EntityMetadata $metadata, array $rawRow): array
    {
        $columnToField = array_flip($metadata->fieldToColumn);
        $fieldRow = [];

        foreach ($rawRow as $column => $value) {
            $field = $columnToField[$column] ?? $column;
            $fieldRow[$field] = $value;
        }

        return $fieldRow;
    }

    private function detectType(mixed $value): ParameterType
    {
        return match (true) {
            is_int($value) => ParameterType::INTEGER,
            is_bool($value) => ParameterType::BOOLEAN,
            default => ParameterType::STRING,
        };
    }

    private function quoteIdentifier(string $identifier): string
    {
        $platform = $this->connection->getDatabasePlatform();
        $parts = explode('.', $identifier);
        $quoted = array_map(
            static fn (string $part): string => $platform->quoteSingleIdentifier($part),
            $parts,
        );

        return implode('.', $quoted);
    }

    /**
     * @param list<mixed> $values
     */
    private function detectArrayType(array $values): ArrayParameterType
    {
        foreach ($values as $value) {
            if (!is_int($value) && !is_bool($value)) {
                return ArrayParameterType::STRING;
            }
        }

        return ArrayParameterType::INTEGER;
    }

    /**
     * @param list<mixed> $values
     * @return list<int|string>
     */
    private function normalizeArrayParameterValues(array $values, ArrayParameterType $type): array
    {
        if ($type === ArrayParameterType::INTEGER) {
            return array_map(static fn (mixed $value): int => (int) $value, $values);
        }

        return array_map(static fn (mixed $value): string => (string) $value, $values);
    }
}
