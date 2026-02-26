<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\Fluent;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Fluent\SyncBuilder;
use SyncForge\Pipeline\SyncPipelineInterface;
use SyncForge\Report\SyncResult;
use SyncForge\SyncContext;

final class SyncBuilderTest extends TestCase
{
    public function testRunWithoutKeyThrows(): void
    {
        $builder = new SyncBuilder(new CapturingPipeline(), EntityStub::class);

        $builder->source([['external_id' => 'A-1']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Sync key must be configured');

        $builder->run();
    }

    public function testRunWithoutSourceThrows(): void
    {
        $builder = new SyncBuilder(new CapturingPipeline(), EntityStub::class);

        $builder->key(['external_id']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Sync source must be configured');

        $builder->run();
    }

    public function testEmptyKeyThrows(): void
    {
        $builder = new SyncBuilder(new CapturingPipeline(), EntityStub::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Key fields cannot be empty');

        $builder->key([]);
    }

    public function testInvalidChunkSizeThrows(): void
    {
        $builder = new SyncBuilder(new CapturingPipeline(), EntityStub::class);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Chunk size must be greater than 0');

        $builder->chunkSize(0);
    }

    public function testRunPassesConfiguredContextToPipeline(): void
    {
        $pipeline = new CapturingPipeline();
        $source = [
            ['external_id' => 'A-1', 'name' => 'Alpha'],
            ['external_id' => 'B-1', 'name' => 'Beta'],
        ];

        (new SyncBuilder($pipeline, EntityStub::class))
            ->key(['external_id'])
            ->source($source)
            ->chunkSize(321)
            ->deleteMissing(true)
            ->dryRun(true)
            ->run();

        self::assertNotNull($pipeline->lastContext);
        self::assertSame(EntityStub::class, $pipeline->lastContext->entityClass);
        self::assertSame(['external_id'], $pipeline->lastContext->keyFields);
        self::assertSame($source, $pipeline->lastContext->source);
        self::assertSame(321, $pipeline->lastContext->chunkSize);
        self::assertTrue($pipeline->lastContext->deleteMissing);
        self::assertTrue($pipeline->lastContext->dryRun);
        self::assertFalse($pipeline->lastContext->continueOnError);
    }
}

final class CapturingPipeline implements SyncPipelineInterface
{
    public ?SyncContext $lastContext = null;

    public function run(SyncContext $context): SyncResult
    {
        $this->lastContext = $context;

        return new SyncResult(
            entityClass: $context->entityClass,
            startedAt: new DateTimeImmutable(),
            finishedAt: new DateTimeImmutable(),
            processedRows: 0,
            inserted: 0,
            updated: 0,
            deleted: 0,
            unchanged: 0,
            chunkCount: 0,
            dryRun: $context->dryRun,
            warnings: [],
        );
    }
}

final class EntityStub
{
}
