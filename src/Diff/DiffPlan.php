<?php

declare(strict_types=1);

namespace SyncForge\Diff;

final class DiffPlan
{
    /**
     * @param list<array<string,mixed>> $inserts
     * @param list<UpdateEntry> $updates
     * @param list<array<string,mixed>> $deletes
     */
    public function __construct(
        public readonly array $inserts,
        public readonly array $updates,
        public readonly array $deletes,
        public readonly int $unchanged,
    ) {
    }
}
