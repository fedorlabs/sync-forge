<?php

declare(strict_types=1);

namespace SyncForge;

final class SyncContext
{
    /**
     * @param list<string> $keyFields
     * @param iterable<array<string,mixed>> $source
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $keyFields,
        public readonly iterable $source,
        public readonly int $chunkSize,
        public readonly bool $deleteMissing,
        public readonly bool $dryRun,
        public readonly bool $continueOnError = false,
    ) {
    }
}
