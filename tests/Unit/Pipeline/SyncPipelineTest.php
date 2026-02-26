<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use SyncForge\Diff\ScalarDiffEngine;
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Exception\MetadataException;
use SyncForge\Executor\BulkExecutorFactory;
use SyncForge\Executor\BulkExecutorInterface;
use SyncForge\Executor\DatabasePlatformContext;
use SyncForge\Executor\ExecutionContext;
use SyncForge\Executor\ExecutionResult;
use SyncForge\Executor\FallbackBatchExecutor;
use SyncForge\Executor\StaticPlatformDetector;
use SyncForge\Key\CompositeKeyResolver;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Metadata\InMemoryEntityMetadataProvider;
use SyncForge\Pipeline\ExistingRowsProviderInterface;
use SyncForge\Pipeline\SyncPipeline;
use SyncForge\SyncContext;

final class SyncPipelineTest extends TestCase
{
    public function testRunAggregatesChunksAndDuplicateWarnings(): void
    {
        $metadata = $this->metadata();
        $pipeline = new SyncPipeline(
            metadataProvider: new InMemoryEntityMetadataProvider([EntityStub::class => $metadata]),
            keyResolver: new CompositeKeyResolver(),
            diffEngine: new ScalarDiffEngine(),
            executorFactory: new BulkExecutorFactory([new FallbackBatchExecutor()]),
            platformDetector: new StaticPlatformDetector(new DatabasePlatformContext('fallback')),
            existingRowsProvider: new class () implements ExistingRowsProviderInterface {
                public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
                {
                    return [];
                }

                public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
                {
                    return [];
                }
            },
        );

        $result = $pipeline->run(new SyncContext(
            entityClass: EntityStub::class,
            keyFields: ['external_id'],
            source: [
                ['external_id' => 'A-1', 'name' => 'Alpha 1'],
                ['external_id' => 'A-1', 'name' => 'Alpha 2'],
                ['external_id' => 'B-1', 'name' => 'Beta'],
            ],
            chunkSize: 2,
            deleteMissing: false,
            dryRun: true,
        ));

        self::assertSame(3, $result->processedRows);
        self::assertSame(2, $result->chunkCount);
        self::assertSame(2, $result->inserted);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->deleted);
        self::assertSame(0, $result->unchanged);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('duplicate keys', $result->warnings[0]);
    }

    public function testDeleteMissingBuildsDeletePlan(): void
    {
        $metadata = $this->metadata();

        $pipeline = new SyncPipeline(
            metadataProvider: new InMemoryEntityMetadataProvider([EntityStub::class => $metadata]),
            keyResolver: new CompositeKeyResolver(),
            diffEngine: new ScalarDiffEngine(),
            executorFactory: new BulkExecutorFactory([new FallbackBatchExecutor()]),
            platformDetector: new StaticPlatformDetector(new DatabasePlatformContext('fallback')),
            existingRowsProvider: new class () implements ExistingRowsProviderInterface {
                public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
                {
                    return [];
                }

                public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
                {
                    return [
                        ['external_id' => 'A-1'],
                        ['external_id' => 'B-1'],
                        ['external_id' => 'C-1'],
                    ];
                }
            },
        );

        $result = $pipeline->run(new SyncContext(
            entityClass: EntityStub::class,
            keyFields: ['external_id'],
            source: [
                ['external_id' => 'A-1', 'name' => 'Alpha'],
                ['external_id' => 'C-1', 'name' => 'Gamma'],
            ],
            chunkSize: 100,
            deleteMissing: true,
            dryRun: true,
        ));

        self::assertSame(2, $result->inserted);
        self::assertSame(1, $result->deleted);
    }

    public function testInvalidKeyFieldThrowsMetadataException(): void
    {
        $metadata = $this->metadata();

        $pipeline = new SyncPipeline(
            metadataProvider: new InMemoryEntityMetadataProvider([EntityStub::class => $metadata]),
            keyResolver: new CompositeKeyResolver(),
            diffEngine: new ScalarDiffEngine(),
            executorFactory: new BulkExecutorFactory([new FallbackBatchExecutor()]),
            platformDetector: new StaticPlatformDetector(new DatabasePlatformContext('fallback')),
            existingRowsProvider: new class () implements ExistingRowsProviderInterface {
                public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
                {
                    return [];
                }

                public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
                {
                    return [];
                }
            },
        );

        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('Key field "unknown_key" is not mapped');

        $pipeline->run(new SyncContext(
            entityClass: EntityStub::class,
            keyFields: ['unknown_key'],
            source: [['external_id' => 'A-1', 'name' => 'Alpha']],
            chunkSize: 10,
            deleteMissing: false,
            dryRun: true,
        ));
    }

    public function testDeleteMissingWithEmptySourceThrowsSafetyException(): void
    {
        $metadata = $this->metadata();

        $pipeline = new SyncPipeline(
            metadataProvider: new InMemoryEntityMetadataProvider([EntityStub::class => $metadata]),
            keyResolver: new CompositeKeyResolver(),
            diffEngine: new ScalarDiffEngine(),
            executorFactory: new BulkExecutorFactory([new FallbackBatchExecutor()]),
            platformDetector: new StaticPlatformDetector(new DatabasePlatformContext('fallback')),
            existingRowsProvider: new class () implements ExistingRowsProviderInterface {
                public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
                {
                    return [];
                }

                public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
                {
                    return [['external_id' => 'A-1']];
                }
            },
        );

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('deleteMissing=true with an empty source is blocked');

        $pipeline->run(new SyncContext(
            entityClass: EntityStub::class,
            keyFields: ['external_id'],
            source: [],
            chunkSize: 100,
            deleteMissing: true,
            dryRun: true,
        ));
    }

    public function testContinueOnErrorCollectsErrorsAndCompletes(): void
    {
        $metadata = $this->metadata();

        $pipeline = new SyncPipeline(
            metadataProvider: new InMemoryEntityMetadataProvider([EntityStub::class => $metadata]),
            keyResolver: new CompositeKeyResolver(),
            diffEngine: new ScalarDiffEngine(),
            executorFactory: new BulkExecutorFactory([new class () implements BulkExecutorInterface {
                public function supports(DatabasePlatformContext $platform): bool
                {
                    return true;
                }

                public function execute(\SyncForge\Diff\DiffPlan $plan, ExecutionContext $context): ExecutionResult
                {
                    if ($context->chunkIndex === 1) {
                        throw new \RuntimeException('boom');
                    }

                    return new ExecutionResult(
                        inserted: count($plan->inserts),
                        updated: count($plan->updates),
                        deleted: count($plan->deletes),
                    );
                }
            }]),
            platformDetector: new StaticPlatformDetector(new DatabasePlatformContext('fallback')),
            existingRowsProvider: new class () implements ExistingRowsProviderInterface {
                public function fetchByIncomingRows(EntityMetadata $metadata, array $keyFields, array $incomingRows): array
                {
                    return [];
                }

                public function fetchAllKeys(EntityMetadata $metadata, array $keyFields): array
                {
                    return [];
                }
            },
        );

        $result = $pipeline->run(new SyncContext(
            entityClass: EntityStub::class,
            keyFields: ['external_id'],
            source: [
                ['external_id' => 'A-1', 'name' => 'Alpha'],
                ['external_id' => 'B-1', 'name' => 'Beta'],
            ],
            chunkSize: 1,
            deleteMissing: false,
            dryRun: false,
            continueOnError: true,
        ));

        self::assertSame(1, $result->inserted);
        self::assertCount(1, $result->errors);
        self::assertFalse($result->isSuccess());
    }

    private function metadata(): EntityMetadata
    {
        return new EntityMetadata(
            entityClass: EntityStub::class,
            tableName: 'products',
            fields: ['external_id', 'name'],
            fieldToColumn: ['external_id' => 'external_id', 'name' => 'name'],
            identifierFields: ['external_id'],
            updatableFields: ['name'],
        );
    }
}

final class EntityStub
{
}
