<?php

declare(strict_types=1);

namespace SyncForge\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use SyncForge\Diff\DiffPlan;
use SyncForge\Executor\BulkExecutorInterface;
use SyncForge\Executor\DatabasePlatformContext;
use SyncForge\Executor\ExecutionContext;
use SyncForge\Executor\ExecutionResult;

final class DbalFallbackBatchExecutor implements BulkExecutorInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function supports(DatabasePlatformContext $platform): bool
    {
        return true;
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
            $inserted = 0;
            $updated = 0;
            $deleted = 0;

            foreach ($plan->inserts as $row) {
                $payload = $this->toColumnPayload($context, $row);
                if ($payload === []) {
                    continue;
                }
                $this->connection->insert($context->metadata->tableName, $payload);
                $inserted++;
            }

            foreach ($plan->updates as $update) {
                if ($update->changedColumns === [] || $update->row === []) {
                    continue;
                }

                $criteria = $this->buildKeyCriteria($context, $update->row);
                $data = $this->toColumnPayload($context, $update->changedColumns);

                if ($data === [] || $criteria === []) {
                    continue;
                }

                $this->connection->update($context->metadata->tableName, $data, $criteria);
                $updated++;
            }

            foreach ($plan->deletes as $row) {
                $criteria = $this->buildKeyCriteria($context, $row);
                if ($criteria === []) {
                    continue;
                }

                $this->connection->delete($context->metadata->tableName, $criteria);
                $deleted++;
            }

            return new ExecutionResult($inserted, $updated, $deleted);
        });
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toColumnPayload(ExecutionContext $context, array $row): array
    {
        $payload = [];
        foreach ($row as $field => $value) {
            $column = $context->metadata->fieldToColumn[$field] ?? null;
            if ($column === null) {
                continue;
            }
            $payload[$column] = $value;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildKeyCriteria(ExecutionContext $context, array $row): array
    {
        $criteria = [];
        foreach ($context->keyFields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $column = $context->metadata->fieldToColumn[$field] ?? null;
            if ($column === null) {
                continue;
            }
            $criteria[$column] = $row[$field];
        }

        return $criteria;
    }
}
