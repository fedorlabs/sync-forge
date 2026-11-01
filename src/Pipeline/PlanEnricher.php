<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

use Closure;
use DateTimeImmutable;
use SyncForge\Diff\DiffPlan;
use SyncForge\Diff\UpdateEntry;
use SyncForge\SyncContext;

/**
 * Adds write-time fields to a diff plan: defaults for inserted rows and fields touched by changes.
 * Closures and the implicit "now" are resolved once, so every row of a run gets the same value.
 */
final class PlanEnricher
{
    /** @var array<string,mixed> */
    private array $insertDefaults = [];

    /** @var list<array{rule:TouchRule,value:mixed}> */
    private array $touches = [];

    public function __construct(SyncContext $context, DateTimeImmutable $now)
    {
        foreach ($context->insertDefaults as $field => $value) {
            $this->insertDefaults[$field] = $value instanceof Closure ? $value() : $value;
        }

        foreach ($context->touchRules as $rule) {
            $value = $rule->value instanceof Closure ? ($rule->value)() : ($rule->value ?? $now);
            $this->touches[] = ['rule' => $rule, 'value' => $value];
        }
    }

    public function enrich(DiffPlan $plan): DiffPlan
    {
        if ($this->insertDefaults === [] && $this->touches === []) {
            return $plan;
        }

        $inserts = $plan->inserts;
        if ($this->insertDefaults !== []) {
            $inserts = array_map(fn (array $row): array => $row + $this->insertDefaults, $plan->inserts);
        }

        $updates = $plan->updates;
        if ($this->touches !== []) {
            $updates = array_map(fn (UpdateEntry $entry): UpdateEntry => $this->touch($entry), $plan->updates);
        }

        return new DiffPlan($inserts, $updates, $plan->deletes, $plan->unchanged);
    }

    private function touch(UpdateEntry $entry): UpdateEntry
    {
        $changed = array_keys($entry->changedColumns);
        $columns = $entry->changedColumns;

        foreach ($this->touches as ['rule' => $rule, 'value' => $value]) {
            if (array_key_exists($rule->field, $entry->changedColumns)) {
                continue;
            }

            $matches = $rule->watchedFields === null
                ? $changed !== []
                : array_intersect($rule->watchedFields, $changed) !== [];
            if ($matches) {
                $columns[$rule->field] = $value;
            }
        }

        return $columns === $entry->changedColumns ? $entry : new UpdateEntry($entry->key, $columns, $entry->row);
    }
}
