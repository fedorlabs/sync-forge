<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use SyncForge\Report\SyncResult;
use SyncForge\SyncContext;

interface SyncPipelineInterface
{
    public function run(SyncContext $context): SyncResult;
}
