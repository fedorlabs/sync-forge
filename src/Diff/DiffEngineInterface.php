<?php

declare(strict_types=1);

namespace SyncForge\Diff;

interface DiffEngineInterface
{
    /**
     * @param array<string,array<string,mixed>> $incomingByKey
     * @param array<string,array<string,mixed>> $existingByKey
     */
    public function diff(array $incomingByKey, array $existingByKey, DiffContext $context): DiffPlan;
}
