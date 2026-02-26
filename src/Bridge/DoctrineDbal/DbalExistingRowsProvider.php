<?php

declare(strict_types=1);

namespace SyncForge\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use SyncForge\Exception\MetadataException;
use SyncForge\Key\KeyResolverInterface;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Pipeline\ExistingRowsProviderInterface;

final class DbalExistingRowsProvider implements ExistingRowsProviderInterface
{
    private readonly string $identifierQuote;

    public function __construct(
        private readonly Connection $connection,
        private readonly KeyResolverInterface $keyResolver,
    ) {
        $platformClass = strtolower($this->connection->getDatabasePlatform()::class);
        $this->identifierQuote = str_contains($platformClass, 'mysql') || str_contains($platformClass, 'mariadb')
            ? '`'
            : '"';
    }

    /**
     * @throws Exception
     */
    public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
    {
        if ($incomingRows === []) {
            return [];
        }

        $indexed = $this->keyResolver->indexByKey($incomingRows, $keyFields);
        if ($indexed->rowsByKey === []) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $selectColumns = [];
        foreach ($metadata->fields as $field) {
            $column = $metadata->fieldToColumn[$field] ?? null;
            if ($column === null) {
                throw new MetadataException(sprintf('Field "%s" has no column mapping.', $field));
            }
            $selectColumns[] = $column;
        }

        $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $selectColumns);
        $qb->select(...$quotedColumns)->from($this->quoteIdentifier($metadata->tableName));

        $rowNum = 0;
        foreach ($indexed->rowsByKey as $row) {
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

            $qb->orWhere('(' . implode(' AND ', $parts) . ')');
            $rowNum++;
        }

        $rawRows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(fn (array $rawRow): array => $this->mapColumnRowToFieldRow($metadata, $rawRow), $rawRows);
    }

    /**
     * @throws Exception
     */
    public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
    {
        if ($keyFields === []) {
            return [];
        }

        $columns = [];
        foreach ($keyFields as $keyField) {
            $columns[] = $metadata->fieldToColumn[$keyField] ?? $keyField;
        }

        $qb = $this->connection->createQueryBuilder();
        $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $rawRows = $qb->select(...$quotedColumns)->from($this->quoteIdentifier($metadata->tableName))->executeQuery()->fetchAllAssociative();

        return array_map(fn (array $rawRow): array => $this->mapColumnRowToFieldRow($metadata, $rawRow), $rawRows);
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
        $parts = explode('.', $identifier);
        $quoted = array_map(
            fn (string $part): string => $this->identifierQuote
                . str_replace($this->identifierQuote, $this->identifierQuote . $this->identifierQuote, $part)
                . $this->identifierQuote,
            $parts,
        );

        return implode('.', $quoted);
    }
}
