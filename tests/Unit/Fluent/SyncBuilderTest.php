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

    public function testScopeIsPassedToContext(): void
    {
        $pipeline = new CapturingPipeline();
        (new SyncBuilder($pipeline, EntityStub::class))
            ->key(['external_id'])->source([])->scope(['supplier_id' => 42, 'archived' => null])->run();

        self::assertSame(['supplier_id' => 42, 'archived' => null], $pipeline->lastContext?->scope);
    }

    public function testScopeRejectsNonScalarValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be scalar or null');

        (new SyncBuilder(new CapturingPipeline(), EntityStub::class))->scope(['supplier_id' => [42]]);
    }

    public function testScopeRejectsListOfValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new SyncBuilder(new CapturingPipeline(), EntityStub::class))->scope([42]);
    }

    public function testWriteTimeOptionsArePassedToContext(): void
    {
        $pipeline = new CapturingPipeline();
        (new SyncBuilder($pipeline, EntityStub::class))
            ->key(['external_id'])->source([])
            ->setOnInsert(['created_at' => 'T0'])
            ->touchOnChange('updated_at')
            ->touchOnChange('price_updated_at', ['price'], 'T1')
            ->returnUpdated()->returnDeleted()->run();

        $context = $pipeline->lastContext;
        self::assertNotNull($context);
        self::assertSame(['created_at' => 'T0'], $context->insertDefaults);
        self::assertTrue($context->returnUpdated);
        self::assertTrue($context->returnDeleted);
        self::assertSame('updated_at', $context->touchRules[0]->field);
        self::assertNull($context->touchRules[0]->watchedFields);
        self::assertSame(['price'], $context->touchRules[1]->watchedFields);
        self::assertSame('T1', $context->touchRules[1]->value);
    }

    public function testTouchOnChangeRejectsEmptyWatchedList(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new SyncBuilder(new CapturingPipeline(), EntityStub::class))->touchOnChange('updated_at', []);
    }

    public function testSetOnInsertRejectsListOfValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new SyncBuilder(new CapturingPipeline(), EntityStub::class))->setOnInsert(['x']);
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
