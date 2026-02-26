<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use SyncForge\Diff\DiffPlan;
use SyncForge\Exception\SyncExecutionException;

/**
 * @internal
 * Dry-run-only fallback used as a safety net when no DBAL-backed executor is configured.
 */
final class FallbackBatchExecutor implements BulkExecutorInterface
{
    public function supports(DatabasePlatformContext $platform): bool
    {
        return true;
    }

    public function execute(DiffPlan $plan, ExecutionContext $context): ExecutionResult
    {
        if (!$context->dryRun) {
            throw new SyncExecutionException(
                'FallbackBatchExecutor is dry-run only. Configure a DBAL executor for write operations.',
            );
        }

        return new ExecutionResult(
            inserted: count($plan->inserts),
            updated: count($plan->updates),
            deleted: count($plan->deletes),
        );
    }
}
