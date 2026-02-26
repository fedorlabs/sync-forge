<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use DateTimeImmutable;
use Exception;
use SyncForge\Chunk\ChunkIterator;
use SyncForge\Diff\DiffContext;
use SyncForge\Diff\DiffEngineInterface;
use SyncForge\Diff\DiffPlan;
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Exception\MetadataException;
use SyncForge\Executor\BulkExecutorFactory;
use SyncForge\Executor\ExecutionContext;
use SyncForge\Executor\PlatformDetectorInterface;
use SyncForge\Key\KeyResolverInterface;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Metadata\EntityMetadataProviderInterface;
use SyncForge\Report\SyncResult;
use SyncForge\SyncContext;

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

        $executor = $this->executorFactory->forPlatform($this->platformDetector->detect());

        $processedRows = 0;
        $inserted = 0;
        $updated = 0;
        $deleted = 0;
        $unchanged = 0;
        $chunkCount = 0;
        $warnings = [];
        $errors = [];
        $incomingKeySet = [];

        foreach (ChunkIterator::fromIterable($context->source, $context->chunkSize) as $index => $chunkRows) {
            $chunkCount++;
            $processedRows += count($chunkRows);

            $incomingIndex = $this->keyResolver->indexByKey($chunkRows, $context->keyFields);
            if ($incomingIndex->duplicateCount > 0) {
                $warnings[] = sprintf('Chunk %d contains %d duplicate keys; last row wins.', $index, $incomingIndex->duplicateCount);
            }

            foreach (array_keys($incomingIndex->rowsByKey) as $key) {
                $incomingKeySet[$key] = true;
            }

            try {
                $existingRows = $this->existingRowsProvider->fetchByIncomingRows($metadata, $context->keyFields, $chunkRows);
                $existingIndex = $this->keyResolver->indexByKey($existingRows, $context->keyFields);

                $plan = $this->diffEngine->diff(
                    $incomingIndex->rowsByKey,
                    $existingIndex->rowsByKey,
                    new DiffContext($metadata, $context->keyFields),
                );

                $executionResult = $executor->execute($plan, new ExecutionContext(
                    metadata: $metadata,
                    keyFields: $context->keyFields,
                    dryRun: $context->dryRun,
                    chunkIndex: $index,
                ));

                $inserted += $executionResult->inserted;
                $updated += $executionResult->updated;
                $deleted += $executionResult->deleted;
                $unchanged += $plan->unchanged;
            } catch (Exception $e) {
                if (!$context->continueOnError) {
                    throw $e;
                }
                $errors[] = sprintf('Chunk %d failed: %s', $index, $e->getMessage());
            }
        }

        if ($context->deleteMissing) {
            if ($processedRows === 0) {
                throw new InvalidConfigurationException(
                    'deleteMissing=true with an empty source is blocked to prevent full-table deletion.',
                );
            }

            try {
                $deletePlan = $this->buildDeleteMissingPlan($metadata, $context->keyFields, $incomingKeySet);
                if ($deletePlan !== null) {
                    $executionResult = $executor->execute($deletePlan, new ExecutionContext(
                        metadata: $metadata,
                        keyFields: $context->keyFields,
                        dryRun: $context->dryRun,
                        chunkIndex: null,
                    ));
                    $deleted += $executionResult->deleted;
                }
            } catch (Exception $e) {
                if (!$context->continueOnError) {
                    throw $e;
                }
                $errors[] = sprintf('Delete-missing phase failed: %s', $e->getMessage());
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
     * @param list<string> $keyFields
     * @param array<string,bool> $incomingKeySet
     */
    private function buildDeleteMissingPlan(EntityMetadata $metadata, array $keyFields, array $incomingKeySet): ?DiffPlan
    {
        $existingKeyRows = $this->existingRowsProvider->fetchAllKeys($metadata, $keyFields);
        if ($existingKeyRows === []) {
            return null;
        }

        $toDelete = [];
        foreach ($existingKeyRows as $row) {
            $signature = $this->keyResolver->makeKey($row, $keyFields);
            if (!isset($incomingKeySet[$signature])) {
                $toDelete[] = $row;
            }
        }

        if ($toDelete === []) {
            return null;
        }

        return new DiffPlan(inserts: [], updates: [], deletes: $toDelete, unchanged: 0);
    }
}
