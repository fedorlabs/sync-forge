<?php

declare(strict_types=1);

namespace SyncForge\Key;

final class KeyIndex
{
    /**
     * @param array<string,array<string,mixed>> $rowsByKey
     */
    public function __construct(
        public readonly array $rowsByKey,
        public readonly int $duplicateCount,
    ) {
    }
}
