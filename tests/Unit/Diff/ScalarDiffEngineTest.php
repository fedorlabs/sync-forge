<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\Diff;

use JsonException;
use PHPUnit\Framework\TestCase;
use SyncForge\Diff\DiffContext;
use SyncForge\Diff\ScalarDiffEngine;
use SyncForge\Key\CompositeKeyResolver;
use SyncForge\Metadata\EntityMetadata;

final class ScalarDiffEngineTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testClassifiesInsertUpdateAndUnchanged(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['name', 'price', 'updated_at']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $kA = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);
        $kB = $resolver->makeKey(['external_id' => 'B-1'], ['external_id']);
        $kC = $resolver->makeKey(['external_id' => 'C-1'], ['external_id']);

        $incoming = [
            $kA => ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100, 'updated_at' => '2025-01-01T00:00:00+00:00'],
            $kB => ['external_id' => 'B-1', 'name' => 'Beta+', 'price' => 250, 'updated_at' => '2025-01-02T00:00:00+00:00'],
            $kC => ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300, 'updated_at' => '2025-01-03T00:00:00+00:00'],
        ];

        $existing = [
            $kA => ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100, 'updated_at' => '2025-01-01T00:00:00+00:00'],
            $kB => ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200, 'updated_at' => '2025-01-02T00:00:00+00:00'],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(1, $plan->inserts);
        self::assertCount(1, $plan->updates);
        self::assertSame(1, $plan->unchanged);
        self::assertSame('C-1', $plan->inserts[0]['external_id']);
        self::assertSame('B-1', $plan->updates[0]->row['external_id']);
        self::assertSame(['name' => 'Beta+', 'price' => 250], $plan->updates[0]->changedColumns);
    }

    /**
     * @throws JsonException
     */
    public function testSkipsFieldsOutsideUpdatableSet(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['name']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);

        $incoming = [
            $key => ['external_id' => 'A-1', 'name' => 'Alpha', 'ignored' => 'incoming'],
        ];

        $existing = [
            $key => ['external_id' => 'A-1', 'name' => 'Alpha', 'ignored' => 'existing'],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(0, $plan->updates);
        self::assertSame(1, $plan->unchanged);
    }

    /**
     * @throws JsonException
     */
    public function testNormalizesDateTimeInterface(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['updated_at']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);

        $incoming = [
            $key => ['external_id' => 'A-1', 'updated_at' => new \DateTimeImmutable('2025-01-01T00:00:00+00:00')],
        ];

        $existing = [
            $key => ['external_id' => 'A-1', 'updated_at' => new \DateTimeImmutable('2025-01-01T00:00:00+00:00')],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(0, $plan->updates);
        self::assertSame(1, $plan->unchanged);
    }

    public function testNormalizesDateTimeStringFromDatabaseFormat(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['updated_at']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);

        $incoming = [
            $key => ['external_id' => 'A-1', 'updated_at' => new \DateTimeImmutable('2025-01-01T00:00:00+00:00')],
        ];

        $existing = [
            $key => ['external_id' => 'A-1', 'updated_at' => '2025-01-01 00:00:00'],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(0, $plan->updates);
        self::assertSame(1, $plan->unchanged);
    }

    public function testSameUtcMomentInDifferentTimezonesIsUnchanged(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['updated_at']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);

        // 2025-01-01 03:00 UTC+3 == 2025-01-01 00:00 UTC
        $incoming = [
            $key => ['external_id' => 'A-1', 'updated_at' => new \DateTimeImmutable('2025-01-01T03:00:00+03:00')],
        ];

        $existing = [
            $key => ['external_id' => 'A-1', 'updated_at' => '2025-01-01 00:00:00'],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(0, $plan->updates);
        self::assertSame(1, $plan->unchanged);
    }

    public function testDateTimeStringWithMicrosecondsIsUnchanged(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['updated_at']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);

        $incoming = [
            $key => ['external_id' => 'A-1', 'updated_at' => new \DateTimeImmutable('2025-06-15T10:30:00+00:00')],
        ];

        $existing = [
            $key => ['external_id' => 'A-1', 'updated_at' => '2025-06-15 10:30:00.000000'],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(0, $plan->updates);
        self::assertSame(1, $plan->unchanged);
    }

    public function testNullDateTimeOnBothSidesIsUnchanged(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['updated_at']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);

        $incoming = [
            $key => ['external_id' => 'A-1', 'updated_at' => null],
        ];

        $existing = [
            $key => ['external_id' => 'A-1', 'updated_at' => null],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(0, $plan->updates);
        self::assertSame(1, $plan->unchanged);
    }

    public function testNullIncomingVsNonNullExistingProducesUpdate(): void
    {
        $engine = new ScalarDiffEngine();
        $metadata = $this->metadata(['updated_at']);
        $context = new DiffContext($metadata, ['external_id']);
        $resolver = new CompositeKeyResolver();

        $key = $resolver->makeKey(['external_id' => 'A-1'], ['external_id']);

        $incoming = [
            $key => ['external_id' => 'A-1', 'updated_at' => null],
        ];

        $existing = [
            $key => ['external_id' => 'A-1', 'updated_at' => '2025-01-01 00:00:00'],
        ];

        $plan = $engine->diff($incoming, $existing, $context);

        self::assertCount(1, $plan->updates);
        self::assertSame(['updated_at' => null], $plan->updates[0]->changedColumns);
    }

    /**
     * @param list<string> $updatableFields
     */
    private function metadata(array $updatableFields): EntityMetadata
    {
        return new EntityMetadata(
            entityClass: ProductStub::class,
            tableName: 'products',
            fields: ['external_id', 'name', 'price', 'updated_at', 'ignored'],
            fieldToColumn: [
                'external_id' => 'external_id',
                'name' => 'name',
                'price' => 'price',
                'updated_at' => 'updated_at',
                'ignored' => 'ignored',
            ],
            identifierFields: ['external_id'],
            updatableFields: $updatableFields,
        );
    }
}

final class ProductStub
{
}
