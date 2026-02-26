<?php

declare(strict_types=1);

namespace SyncForge\Executor;

use SyncForge\Diff\DiffPlan;

interface BulkExecutorInterface
{
    public function supports(DatabasePlatformContext $platform): bool;

    public function execute(DiffPlan $plan, ExecutionContext $context): ExecutionResult;
}
