<?php

declare(strict_types=1);

namespace SyncForge\Diff;

final class UpdateEntry
{
    /**
     * @param array<string,mixed> $changedColumns
     * @param array<string,mixed> $row
     */
    public function __construct(
        public readonly string $key,
        public readonly array $changedColumns,
        public readonly array $row,
    ) {
    }
}
