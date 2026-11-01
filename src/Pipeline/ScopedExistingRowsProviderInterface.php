<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use SyncForge\Metadata\EntityMetadata;

/**
 * Existing rows provider that can restrict every lookup to rows matching a scope.
 * Required when a sync is run with a scope; a provider without it is rejected so that
 * rows outside the scope are never read or deleted by accident.
 */
interface ScopedExistingRowsProviderInterface extends ExistingRowsProviderInterface, StreamedExistingKeysProviderInterface
{
    /**
     * @param list<string> $keyFields
     * @param list<array<string,mixed>> $incomingRows
     * @param array<string,scalar|null> $scope
     * @return list<array<string,mixed>>
     */
    public function fetchByIncomingRowsInScope(EntityMetadata $metadata, array $keyFields, array $incomingRows, array $scope): array;

    /**
     * @param list<string> $keyFields
     * @param array<string,scalar|null> $scope
     * @return iterable<list<array<string,mixed>>>
     */
    public function iterateAllKeysInScope(EntityMetadata $metadata, array $keyFields, array $scope, int $batchSize = 1000): iterable;
}
