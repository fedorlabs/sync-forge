<?php

declare(strict_types=1);

namespace SyncForge\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use SyncForge\Diff\DiffPlan;
use SyncForge\Executor\BulkExecutorInterface;
use SyncForge\Executor\DatabasePlatformContext;
use SyncForge\Executor\ExecutionContext;
use SyncForge\Executor\ExecutionResult;

final class DbalPostgresBulkExecutor implements BulkExecutorInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function supports(DatabasePlatformContext $platform): bool
    {
        return $platform->name === 'postgresql';
    }

    /**
     * @throws Exception
     */
    public function execute(DiffPlan $plan, ExecutionContext $context): ExecutionResult
    {
        if ($context->dryRun) {
            return new ExecutionResult(
                inserted: count($plan->inserts),
                updated: count($plan->updates),
                deleted: count($plan->deletes),
            );
        }

        return $this->connection->transactional(function () use ($plan, $context): ExecutionResult {
            $inserted = $this->executeUpsertInsertBatch($plan, $context);
            $updated = $this->executeUpdates($plan, $context);
            $deleted = $this->executeDeletes($plan, $context);

            return new ExecutionResult($inserted, $updated, $deleted);
        });
    }

    /**
     * @throws Exception
     */
    private function executeUpsertInsertBatch(DiffPlan $plan, ExecutionContext $context): int
    {
        if ($plan->inserts === []) {
            return 0;
        }

        $columns = $this->resolveColumnsFromFirstRow($plan->inserts[0], $context);
        if ($columns === []) {
            return 0;
        }

        $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $quotedTable = $this->quoteIdentifier($context->metadata->tableName);

        $params = [];
        $types = [];
        $valueTuples = [];

        foreach ($plan->inserts as $rowIndex => $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $param = sprintf('v_%d_%s', $rowIndex, $column);
                $field = $this->resolveFieldByColumn($column, $context);
                $value = $field !== null && array_key_exists($field, $row) ? $row[$field] : null;

                $placeholders[] = ':' . $param;
                $params[$param] = $value;
                $types[$param] = $this->detectType($value);
            }
            $valueTuples[] = '(' . implode(', ', $placeholders) . ')';
        }

        $keyColumns = array_map(
            fn (string $field): string => $context->metadata->fieldToColumn[$field] ?? $field,
            $context->keyFields,
        );

        $updateColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => !in_array($column, $keyColumns, true),
        ));

        $conflictColumns = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $keyColumns));
        $onConflict = 'DO NOTHING';

        if ($updateColumns !== []) {
            $assignments = array_map(
                fn (string $column): string => sprintf(
                    '%s = EXCLUDED.%s',
                    $this->quoteIdentifier($column),
                    $this->quoteIdentifier($column),
                ),
                $updateColumns,
            );
            $onConflict = 'DO UPDATE SET ' . implode(', ', $assignments);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) %s',
            $quotedTable,
            implode(', ', $quotedColumns),
            implode(', ', $valueTuples),
            $conflictColumns,
            $onConflict,
        );

        $this->connection->executeStatement($sql, $params, $types);

        return count($plan->inserts);
    }

    /**
     * @throws Exception
     */
    private function executeUpdates(DiffPlan $plan, ExecutionContext $context): int
    {
        $updated = 0;

        foreach ($plan->updates as $update) {
            if ($update->changedColumns === [] || $update->row === []) {
                continue;
            }

            $criteria = $this->buildCriteria($update->row, $context);
            if ($criteria['sql'] === '') {
                continue;
            }

            $set = $this->buildSetClause($update->changedColumns, $context, 'u');
            if ($set['sql'] === '') {
                continue;
            }

            $sql = sprintf(
                'UPDATE %s SET %s WHERE %s',
                $this->quoteIdentifier($context->metadata->tableName),
                $set['sql'],
                $criteria['sql'],
            );

            $this->connection->executeStatement(
                $sql,
                array_merge($set['params'], $criteria['params']),
                array_merge($set['types'], $criteria['types']),
            );

            $updated++;
        }

        return $updated;
    }

    /**
     * @throws Exception
     */
    private function executeDeletes(DiffPlan $plan, ExecutionContext $context): int
    {
        $deleted = 0;
        foreach ($plan->deletes as $row) {
            $criteria = $this->buildCriteria($row, $context);
            if ($criteria['sql'] === '') {
                continue;
            }

            $sql = sprintf(
                'DELETE FROM %s WHERE %s',
                $this->quoteIdentifier($context->metadata->tableName),
                $criteria['sql'],
            );

            $this->connection->executeStatement($sql, $criteria['params'], $criteria['types']);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private function resolveColumnsFromFirstRow(array $row, ExecutionContext $context): array
    {
        $columns = [];
        foreach (array_keys($row) as $field) {
            $column = $context->metadata->fieldToColumn[$field] ?? null;
            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    private function resolveFieldByColumn(string $column, ExecutionContext $context): ?string
    {
        foreach ($context->metadata->fieldToColumn as $field => $mappedColumn) {
            if ($mappedColumn === $column) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $changes
     * @return array{sql:string,params:array<string,mixed>,types:array<string,ParameterType>}
     */
    private function buildSetClause(array $changes, ExecutionContext $context, string $prefix): array
    {
        $parts = [];
        $params = [];
        $types = [];

        foreach ($changes as $field => $value) {
            $column = $context->metadata->fieldToColumn[$field] ?? null;
            if ($column === null) {
                continue;
            }

            $param = sprintf('%s_set_%s', $prefix, $column);
            $parts[] = sprintf('%s = :%s', $this->quoteIdentifier($column), $param);
            $params[$param] = $value;
            $types[$param] = $this->detectType($value);
        }

        return ['sql' => implode(', ', $parts), 'params' => $params, 'types' => $types];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{sql:string,params:array<string,mixed>,types:array<string,ParameterType>}
     */
    private function buildCriteria(array $row, ExecutionContext $context): array
    {
        $parts = [];
        $params = [];
        $types = [];

        foreach ($context->keyFields as $keyField) {
            $column = $context->metadata->fieldToColumn[$keyField] ?? null;
            if ($column === null || !array_key_exists($keyField, $row)) {
                continue;
            }

            $value = $row[$keyField];
            if ($value === null) {
                $parts[] = sprintf('%s IS NULL', $this->quoteIdentifier($column));
                continue;
            }

            $param = sprintf('k_%s', $column);
            $parts[] = sprintf('%s = :%s', $this->quoteIdentifier($column), $param);
            $params[$param] = $value;
            $types[$param] = $this->detectType($value);
        }

        return ['sql' => implode(' AND ', $parts), 'params' => $params, 'types' => $types];
    }

    private function detectType(mixed $value): ParameterType
    {
        return match (true) {
            $value === null => ParameterType::NULL,
            is_int($value) => ParameterType::INTEGER,
            is_bool($value) => ParameterType::BOOLEAN,
            default => ParameterType::STRING,
        };
    }

    private function quoteIdentifier(string $identifier): string
    {
        $parts = explode('.', $identifier);
        $quoted = array_map(
            static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"',
            $parts,
        );

        return implode('.', $quoted);
    }
}
