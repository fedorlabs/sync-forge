<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use DateTimeImmutable;
use Error;
use SyncForge\Chunk\ChunkIterator;
use SyncForge\Diff\DiffContext;
use SyncForge\Diff\DiffEngineInterface;
use SyncForge\Diff\DiffPlan;
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Exception\MetadataException;
use SyncForge\Exception\SyncExecutionException;
use SyncForge\Executor\BulkExecutorFactory;
use SyncForge\Executor\BulkExecutorInterface;
use SyncForge\Executor\ExecutionContext;
use SyncForge\Executor\PlatformDetectorInterface;
use SyncForge\Key\KeyResolverInterface;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Metadata\EntityMetadataProviderInterface;
use SyncForge\Report\ErrorEntry;
use SyncForge\Report\SyncResult;
use SyncForge\SyncContext;
use Throwable;

final class SyncPipeline implements SyncPipelineInterface
{
    public function __construct(
        private readonly EntityMetadataProviderInterface $metadataProvider,
        private readonly KeyResolverInterface $keyResolver,
        private readonly DiffEngineInterface $diffEngine,
        private readonly BulkExecutorFactory $executorFactory,
        private readonly PlatformDetectorInterface $platformDetector,
        private readonly ExistingRowsProviderInterface $existingRowsProvider,
    ) {
    }

    public function run(SyncContext $context): SyncResult
    {
        $startedAt = new DateTimeImmutable();
        $metadata = $this->metadataProvider->get($context->entityClass);
        $this->validateKeyFields($metadata, $context->keyFields);
        $this->validateScope($metadata, $context->keyFields, $context->scope);
        $this->validateWriteTimeFields($metadata, $context);
        $enricher = new PlanEnricher($context, $startedAt);
        $keyProjection = array_values(array_unique([...$context->keyFields, ...$metadata->identifierFields]));

        $platformContext = $this->platformDetector->detect();
        $executor = $this->executorFactory->forPlatform($platformContext);

        $processedRows = 0;
        $inserted = 0;
        $updated = 0;
        $deleted = 0;
        $unchanged = 0;
        $chunkCount = 0;
        $warnings = [];
        /** @var list<ErrorEntry> $errors */
        $errors = [];
        $incomingKeySet = [];
        /** @var list<array<string,mixed>> $insertedKeys */
        $insertedKeys = [];
        /** @var list<array<string,mixed>> $updatedKeys */
        $updatedKeys = [];
        /** @var list<array<string,mixed>> $deletedKeys */
        $deletedKeys = [];

        foreach (ChunkIterator::fromIterable($context->source, $context->chunkSize) as $index => $chunkRows) {
            $chunkCount++;
            $processedRows += count($chunkRows);

            $chunkRows = $this->applyScopeToRows($chunkRows, $context->scope);
            $incomingIndex = $this->keyResolver->indexByKey($chunkRows, $context->keyFields);
            if ($incomingIndex->duplicateCount > 0) {
                $warnings[] = sprintf('Chunk %d contains %d duplicate keys; last row wins.', $index, $incomingIndex->duplicateCount);
            }

            $repeatedKeys = 0;
            foreach (array_keys($incomingIndex->rowsByKey) as $key) {
                if (isset($incomingKeySet[$key])) {
                    $repeatedKeys++;
                }
                $incomingKeySet[$key] = true;
            }
            if ($repeatedKeys > 0) {
                $warnings[] = sprintf('Chunk %d repeats %d keys from earlier chunks; last row wins.', $index, $repeatedKeys);
            }

            try {
                $existingRows = $this->fetchExistingRows($metadata, $context, $chunkRows);
                $existingIndex = $this->keyResolver->indexByKey($existingRows, $context->keyFields);
                foreach (array_keys($existingIndex->rowsByKey) as $existingKey) {
                    if (!isset($incomingIndex->rowsByKey[$existingKey])) {
                        throw new InvalidConfigurationException('Database key does not match the source key; normalize key values and types before syncing.');
                    }
                }

                $plan = $this->diffEngine->diff(
                    $incomingIndex->rowsByKey,
                    $existingIndex->rowsByKey,
                    new DiffContext($metadata, $context->keyFields, $platformContext->platform),
                );

                $plan = $enricher->enrich($plan);

                $executionResult = $executor->execute($plan, new ExecutionContext(
                    metadata: $metadata,
                    keyFields: $context->keyFields,
                    dryRun: $context->dryRun,
                    chunkIndex: $index,
                ));

                if ($context->returnInserted && $plan->inserts !== []) {
                    $insertedKeys = [...$insertedKeys, ...$this->collectInsertedKeys($metadata, $context, $plan->inserts)];
                }

                if ($context->returnUpdated) {
                    foreach ($plan->updates as $entry) {
                        $updatedKeys[] = $this->projectFields($existingIndex->rowsByKey[$entry->key] ?? $entry->row, $keyProjection);
                    }
                }

                $inserted += $executionResult->inserted;
                $updated += $executionResult->updated;
                $deleted += $executionResult->deleted;
                $unchanged += $plan->unchanged;
            } catch (Throwable $e) {
                if (!$context->continueOnError) {
                    throw $e;
                }
                $errors[] = new ErrorEntry(
                    type: $this->classifyException($e),
                    message: $e->getMessage(),
                    chunkIndex: $index,
                );
            }
        }

        if ($context->deleteMissing && $errors !== []) {
            $warnings[] = 'deleteMissing skipped because one or more chunks failed.';
        } elseif ($context->deleteMissing) {
            if ($processedRows === 0) {
                throw new InvalidConfigurationException(
                    'deleteMissing=true with an empty source is blocked to prevent full-table deletion.',
                );
            }

            try {
                $deletion = $this->executeDeleteMissing(
                    metadata: $metadata,
                    keyFields: $context->keyFields,
                    incomingKeySet: $incomingKeySet,
                    executor: $executor,
                    context: $context,
                );
                $deleted += $deletion['deleted'];
                $deletedKeys = [...$deletedKeys, ...$deletion['keys']];
            } catch (Throwable $e) {
                if (!$context->continueOnError) {
                    throw $e;
                }
                $errors[] = new ErrorEntry(
                    type: $this->classifyException($e),
                    message: $e->getMessage(),
                );
            }
        }

        $finishedAt = new DateTimeImmutable();

        return new SyncResult(
            entityClass: $context->entityClass,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            processedRows: $processedRows,
            inserted: $inserted,
            updated: $updated,
            deleted: $deleted,
            unchanged: $unchanged,
            chunkCount: $chunkCount,
            dryRun: $context->dryRun,
            warnings: $warnings,
            errors: $errors,
            insertedKeys: $insertedKeys,
            updatedKeys: $updatedKeys,
            deletedKeys: $deletedKeys,
        );
    }

    /**
     * @param list<string> $keyFields
     */
    private function validateKeyFields(EntityMetadata $metadata, array $keyFields): void
    {
        foreach ($keyFields as $field) {
            if (!in_array($field, $metadata->fields, true)) {
                throw new MetadataException(sprintf(
                    'Key field "%s" is not mapped for %s.',
                    $field,
                    $metadata->entityClass,
                ));
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function fetchExistingRows(EntityMetadata $metadata, SyncContext $context, array $rows): array
    {
        return $this->existingRowsProvider instanceof ScopedExistingRowsProviderInterface && $context->scope !== []
            ? $this->existingRowsProvider->fetchByIncomingRowsInScope($metadata, $context->keyFields, $rows, $context->scope)
            : $this->existingRowsProvider->fetchByIncomingRows($metadata, $context->keyFields, $rows);
    }

    /**
     * Reads the inserted rows back by key, so generated identifiers and defaults are included.
     * A dry run writes nothing, so only the key fields of the planned inserts are returned.
     *
     * @param list<array<string,mixed>> $insertedRows
     * @return list<array<string,mixed>>
     */
    private function collectInsertedKeys(EntityMetadata $metadata, SyncContext $context, array $insertedRows): array
    {
        $fields = $context->keyFields;
        $rows = $insertedRows;

        if (!$context->dryRun) {
            $fields = array_values(array_unique([...$context->keyFields, ...$metadata->identifierFields]));
            $rows = $this->fetchExistingRows($metadata, $context, $insertedRows);
        }

        return array_map(
            static function (array $row) use ($fields): array {
                $projected = [];
                foreach ($fields as $field) {
                    $projected[$field] = $row[$field] ?? null;
                }

                return $projected;
            },
            $rows,
        );
    }

    /**
     * @param list<string> $keyFields
     * @param array<string,scalar|null> $scope
     */
    private function validateScope(EntityMetadata $metadata, array $keyFields, array $scope): void
    {
        if ($scope === []) {
            return;
        }

        foreach (array_keys($scope) as $field) {
            if (!in_array($field, $metadata->fields, true)) {
                throw new MetadataException(sprintf(
                    'Scope field "%s" is not mapped for %s.',
                    $field,
                    $metadata->entityClass,
                ));
            }
        }

        foreach (array_keys($scope) as $field) {
            if (!in_array($field, $keyFields, true)) {
                throw new InvalidConfigurationException(sprintf(
                    'Scope field "%s" must be part of the sync key: with a key that does not include it, an upsert would take over a row that belongs to another scope.',
                    $field,
                ));
            }
        }

        if (!$this->existingRowsProvider instanceof ScopedExistingRowsProviderInterface) {
            throw new InvalidConfigurationException(
                'Scope requires an existing rows provider implementing ScopedExistingRowsProviderInterface.',
            );
        }
    }

    /**
     * Fills scope values into rows that lack them and rejects rows that belong to another scope.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,scalar|null> $scope
     * @return list<array<string,mixed>>
     */
    private function applyScopeToRows(array $rows, array $scope): array
    {
        if ($scope === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            foreach ($scope as $field => $value) {
                $current = $row[$field] ?? null;
                if (!array_key_exists($field, $row) || ($current === null && $value !== null)) {
                    $rows[$index][$field] = $value;
                    continue;
                }

                if ($current === $value || (is_scalar($current) && $value !== null && (string) $current === (string) $value)) {
                    continue;
                }

                throw new InvalidConfigurationException(sprintf('Source row has "%s" outside the sync scope.', $field));
            }
        }

        return $rows;
    }

    private function validateWriteTimeFields(EntityMetadata $metadata, SyncContext $context): void
    {
        $fields = array_keys($context->insertDefaults);
        foreach ($context->touchRules as $rule) {
            $fields = [...$fields, $rule->field, ...($rule->watchedFields ?? [])];
        }

        foreach ($fields as $field) {
            if (!in_array($field, $metadata->fields, true)) {
                throw new MetadataException(sprintf('Field "%s" is not mapped for %s.', $field, $metadata->entityClass));
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $fields
     * @return array<string,mixed>
     */
    private function projectFields(array $row, array $fields): array
    {
        $projected = [];
        foreach ($fields as $field) {
            $projected[$field] = $row[$field] ?? null;
        }

        return $projected;
    }

    /**
     * @param list<string> $keyFields
     * @param array<string,bool> $incomingKeySet
     * @return array{deleted:int,keys:list<array<string,mixed>>}
     */
    private function executeDeleteMissing(
        EntityMetadata $metadata,
        array $keyFields,
        array $incomingKeySet,
        BulkExecutorInterface $executor,
        SyncContext $context,
    ): array {
        $deleted = 0;
        $deletedKeys = [];

        if ($this->existingRowsProvider instanceof ScopedExistingRowsProviderInterface && $context->scope !== []) {
            $batches = $this->existingRowsProvider->iterateAllKeysInScope($metadata, $keyFields, $context->scope, $context->chunkSize);
        } elseif ($this->existingRowsProvider instanceof StreamedExistingKeysProviderInterface) {
            $batches = $this->existingRowsProvider->iterateAllKeys($metadata, $keyFields, $context->chunkSize);
        } else {
            $allRows = $this->existingRowsProvider->fetchAllKeys($metadata, $keyFields);
            $batches = [$allRows];
        }

        foreach ($batches as $existingKeyRows) {
            if ($existingKeyRows === []) {
                continue;
            }

            $toDelete = [];
            foreach ($existingKeyRows as $row) {
                $signature = $this->keyResolver->makeKey($row, $keyFields);
                if (!isset($incomingKeySet[$signature])) {
                    $toDelete[] = $row;
                }
            }

            if ($toDelete === []) {
                continue;
            }

            $executionResult = $executor->execute(
                new DiffPlan(inserts: [], updates: [], deletes: $toDelete, unchanged: 0),
                new ExecutionContext(
                    metadata: $metadata,
                    keyFields: $keyFields,
                    dryRun: $context->dryRun,
                    chunkIndex: null,
                ),
            );

            $deleted += $executionResult->deleted;
            if ($context->returnDeleted) {
                foreach ($toDelete as $row) {
                    $deletedKeys[] = $this->projectFields($row, $keyFields);
                }
            }
        }

        return ['deleted' => $deleted, 'keys' => $deletedKeys];
    }

    private function classifyException(Throwable $e): string
    {
        if ($e instanceof Error) {
            return ErrorEntry::TYPE_INTERNAL;
        }

        if ($e instanceof InvalidConfigurationException || $e instanceof MetadataException) {
            return ErrorEntry::TYPE_VALIDATION;
        }

        if ($e instanceof SyncExecutionException) {
            return ErrorEntry::TYPE_DB;
        }

        return ErrorEntry::TYPE_PIPELINE;
    }
}
