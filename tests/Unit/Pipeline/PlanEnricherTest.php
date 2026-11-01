<?php

declare(strict_types=1);

namespace SyncForge\Tests\Unit\Pipeline;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SyncForge\Diff\DiffPlan;
use SyncForge\Diff\UpdateEntry;
use SyncForge\Pipeline\PlanEnricher;
use SyncForge\Pipeline\TouchRule;
use SyncForge\SyncContext;

final class PlanEnricherTest extends TestCase
{
    public function testInsertDefaultsFillOnlyAbsentFields(): void
    {
        $enricher = new PlanEnricher(
            $this->context(insertDefaults: ['created_at' => 'T0', 'is_new' => false]),
            new DateTimeImmutable('2024-01-01'),
        );

        $plan = $enricher->enrich(new DiffPlan(
            [['sku' => 'A'], ['sku' => 'B', 'is_new' => true]],
            [],
            [],
            0,
        ));

        self::assertSame(
            [['sku' => 'A', 'created_at' => 'T0', 'is_new' => false], ['sku' => 'B', 'is_new' => true, 'created_at' => 'T0']],
            $plan->inserts,
        );
    }

    public function testClosureIsEvaluatedOnceForTheWholeRun(): void
    {
        $calls = 0;
        $enricher = new PlanEnricher(
            $this->context(insertDefaults: ['token' => static function () use (&$calls): int {
                return ++$calls;
            }]),
            new DateTimeImmutable('2024-01-01'),
        );

        $plan = $enricher->enrich(new DiffPlan([['sku' => 'A'], ['sku' => 'B']], [], [], 0));
        $plan = $enricher->enrich(new DiffPlan([['sku' => 'C']], [], [], 0));

        self::assertSame(1, $calls);
        self::assertSame(1, $plan->inserts[0]['token']);
    }

    public function testTouchRulesFireOnlyWhenWatchedFieldsChanged(): void
    {
        $now = new DateTimeImmutable('2024-01-01 10:00:00');
        $enricher = new PlanEnricher(
            $this->context(touchRules: [
                new TouchRule('price_updated_at', ['price']),
                new TouchRule('stock_updated_at', ['stock']),
                new TouchRule('updated_at', null),
            ]),
            $now,
        );

        $plan = $enricher->enrich(new DiffPlan([], [
            new UpdateEntry('k1', ['price' => 5], ['sku' => 'A']),
            new UpdateEntry('k2', ['stock' => 1, 'price' => 6], ['sku' => 'B']),
            new UpdateEntry('k3', ['name' => 'x'], ['sku' => 'C']),
        ], [], 0));

        self::assertSame(['price' => 5, 'price_updated_at' => $now, 'updated_at' => $now], $plan->updates[0]->changedColumns);
        self::assertSame(
            ['stock' => 1, 'price' => 6, 'price_updated_at' => $now, 'stock_updated_at' => $now, 'updated_at' => $now],
            $plan->updates[1]->changedColumns,
        );
        self::assertSame(['name' => 'x', 'updated_at' => $now], $plan->updates[2]->changedColumns);
    }

    public function testAnyChangeRuleIgnoresOtherTouchedFields(): void
    {
        $enricher = new PlanEnricher(
            $this->context(touchRules: [new TouchRule('updated_at', null), new TouchRule('price_updated_at', ['price'])]),
            new DateTimeImmutable('2024-01-01'),
        );

        $plan = $enricher->enrich(new DiffPlan([], [new UpdateEntry('k', ['name' => 'x'], [])], [], 0));

        self::assertArrayNotHasKey('price_updated_at', $plan->updates[0]->changedColumns);
    }

    public function testValueFromTheSourceWinsAndExplicitValueIsUsed(): void
    {
        $enricher = new PlanEnricher(
            $this->context(touchRules: [new TouchRule('updated_at', null, 'fixed')]),
            new DateTimeImmutable('2024-01-01'),
        );

        $plan = $enricher->enrich(new DiffPlan([], [
            new UpdateEntry('k1', ['name' => 'x'], []),
            new UpdateEntry('k2', ['name' => 'y', 'updated_at' => 'from-source'], []),
        ], [], 0));

        self::assertSame('fixed', $plan->updates[0]->changedColumns['updated_at']);
        self::assertSame('from-source', $plan->updates[1]->changedColumns['updated_at']);
    }

    public function testPlanWithoutRulesIsReturnedAsIs(): void
    {
        $plan = new DiffPlan([['sku' => 'A']], [], [], 0);

        self::assertSame($plan, (new PlanEnricher($this->context(), new DateTimeImmutable()))->enrich($plan));
    }

    /**
     * @param array<string,mixed> $insertDefaults
     * @param list<TouchRule> $touchRules
     */
    private function context(array $insertDefaults = [], array $touchRules = []): SyncContext
    {
        return new SyncContext(
            entityClass: 'Stub',
            keyFields: ['sku'],
            source: [],
            chunkSize: 10,
            deleteMissing: false,
            dryRun: false,
            insertDefaults: $insertDefaults,
            touchRules: $touchRules,
        );
    }
}
