<?php

declare(strict_types=1);

namespace SyncForge;

use SyncForge\Pipeline\TouchRule;

final class SyncContext
{
    /**
     * @param list<string> $keyFields
     * @param iterable<array<string,mixed>> $source
     * @param array<string,scalar|null> $scope field => value limiting existing rows, deleteMissing and inserted values
     * @param array<string,mixed> $insertDefaults field => value (or Closure evaluated once per run) set on inserted rows that lack the field
     * @param list<TouchRule> $touchRules fields set on updated rows when watched fields changed
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $keyFields,
        public readonly iterable $source,
        public readonly int $chunkSize,
        public readonly bool $deleteMissing,
        public readonly bool $dryRun,
        public readonly bool $continueOnError = false,
        public readonly array $scope = [],
        public readonly bool $returnInserted = false,
        public readonly array $insertDefaults = [],
        public readonly array $touchRules = [],
        public readonly bool $returnUpdated = false,
        public readonly bool $returnDeleted = false,
    ) {
    }
}
