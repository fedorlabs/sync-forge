<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\Diff;

use JsonException;
use PHPUnit\Framework\TestCase;
use SyncForge\Diff\DiffContext;
use SyncForge\Diff\DiffPlan;
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

    public function testSameInstantInDifferentTimezonesIsUnchangedForTimezoneAwareColumn(): void
    {
        $plan = $this->diffOne(
            ['updated_at' => new \DateTimeImmutable('2025-01-01T03:00:00+03:00')],
            ['updated_at' => '2025-01-01 00:00:00+00'],
            ['updated_at' => 'datetimetz_immutable'],
        );

        self::assertCount(0, $plan->updates);
        self::assertSame(1, $plan->unchanged);
    }

    public function testNaiveColumnComparesWallClockInPhpTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');

        try {
            $same = $this->diffOne(
                ['updated_at' => new \DateTimeImmutable('2025-06-01 12:00:00', new \DateTimeZone('Europe/Amsterdam'))],
                ['updated_at' => '2025-06-01 12:00:00'],
                ['updated_at' => 'datetime_immutable'],
            );
            $shifted = $this->diffOne(
                ['updated_at' => new \DateTimeImmutable('2025-06-01 12:00:00', new \DateTimeZone('Europe/Amsterdam'))],
                ['updated_at' => '2025-06-01 10:00:00'],
                ['updated_at' => 'datetime_immutable'],
            );
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertSame(1, $same->unchanged);
        self::assertCount(1, $shifted->updates);
    }

    public function testBooleanColumnTreatsDriverRepresentationsAsEqual(): void
    {
        foreach ([[true, 1], [true, '1'], [true, 't'], [false, 0], [false, '0'], [false, 'f']] as [$incoming, $existing]) {
            $plan = $this->diffOne(['updated_at' => $incoming], ['updated_at' => $existing], ['updated_at' => 'boolean']);
            self::assertSame(1, $plan->unchanged, var_export($existing, true));
        }

        $changed = $this->diffOne(['updated_at' => true], ['updated_at' => 0], ['updated_at' => 'boolean']);
        self::assertCount(1, $changed->updates);
    }

    public function testIntegerColumnsIgnoreDriverStringRepresentation(): void
    {
        self::assertSame(1, $this->diffOne(['updated_at' => 5], ['updated_at' => '5'], ['updated_at' => 'integer'])->unchanged);
        self::assertSame(1, $this->diffOne(['updated_at' => 5], ['updated_at' => '5'], ['updated_at' => 'bigint'])->unchanged);
        self::assertSame(1, $this->diffOne(['updated_at' => '9007199254740993'], ['updated_at' => 9007199254740993], ['updated_at' => 'bigint'])->unchanged);
        self::assertCount(1, $this->diffOne(['updated_at' => 6], ['updated_at' => '5'], ['updated_at' => 'integer'])->updates);
    }

    public function testDateColumnComparesOnlyTheDay(): void
    {
        $plan = $this->diffOne(
            ['updated_at' => new \DateTimeImmutable('2025-06-01 00:00:00', new \DateTimeZone('Europe/Amsterdam'))],
            ['updated_at' => '2025-06-01'],
            ['updated_at' => 'date_immutable'],
        );

        self::assertSame(1, $plan->unchanged);
    }

    /**
     * @param array<string,mixed> $incomingFields
     * @param array<string,mixed> $existingFields
     * @param array<string,string> $fieldTypes
     */
    private function diffOne(array $incomingFields, array $existingFields, array $fieldTypes): DiffPlan
    {
        $context = new DiffContext($this->metadata(['updated_at'], $fieldTypes), ['external_id']);
        $key = (new CompositeKeyResolver())->makeKey(['external_id' => 'A-1'], ['external_id']);

        return (new ScalarDiffEngine())->diff(
            [$key => ['external_id' => 'A-1', ...$incomingFields]],
            [$key => ['external_id' => 'A-1', ...$existingFields]],
            $context,
        );
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
     * @param array<string,string> $fieldTypes
     */
    private function metadata(array $updatableFields, array $fieldTypes = []): EntityMetadata
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
            fieldTypes: $fieldTypes,
        );
    }
}

final class ProductStub
{
}
