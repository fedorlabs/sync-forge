<?php

declare(strict_types=1);

namespace SyncForge\Diff;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class ScalarDiffEngine implements DiffEngineInterface
{
    public function diff(array $incomingByKey, array $existingByKey, DiffContext $context): DiffPlan
    {
        $inserts = [];
        $updates = [];
        $unchanged = 0;

        foreach ($incomingByKey as $key => $incomingRow) {
            $existing = $existingByKey[$key] ?? null;

            if ($existing === null) {
                $inserts[] = $incomingRow;
                continue;
            }

            $changes = $this->extractChanges($incomingRow, $existing, $context);
            if ($changes === []) {
                $unchanged++;
                continue;
            }

            $updates[] = new UpdateEntry(
                key: $key,
                changedColumns: $changes,
                row: $incomingRow,
            );
        }

        return new DiffPlan(
            inserts: $inserts,
            updates: $updates,
            deletes: [],
            unchanged: $unchanged,
        );
    }

    /**
     * @param array<string,mixed> $incomingRow
     * @param array<string,mixed> $existingRow
     * @return array<string,mixed>
     */
    private function extractChanges(array $incomingRow, array $existingRow, DiffContext $context): array
    {
        $changes = [];

        foreach ($context->metadata->updatableFields as $field) {
            if (!array_key_exists($field, $incomingRow)) {
                continue;
            }

            $incoming = $this->normalize($incomingRow[$field]);
            $existing = $this->normalize($existingRow[$field] ?? null);

            if ($incoming !== $existing) {
                $changes[$field] = $incomingRow[$field];
            }
        }

        return $changes;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        if (is_string($value)) {
            $normalized = $this->normalizeDateString($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $value;
    }

    private function normalizeDateString(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        $hasTimezone = preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $value) === 1;
        $utc = new DateTimeZone('UTC');

        if ($hasTimezone) {
            $formats = [
                DateTimeInterface::ATOM,
                'Y-m-d\TH:i:s.uP',
            ];

            foreach ($formats as $format) {
                $dt = DateTimeImmutable::createFromFormat($format, $value);
                if ($dt instanceof DateTimeImmutable) {
                    return $dt->setTimezone($utc)->format('Y-m-d\TH:i:sP');
                }
            }

            return null;
        }

        $formats = [
            '!Y-m-d H:i:s',
            '!Y-m-d H:i:s.u',
            '!Y-m-d\TH:i:s',
            '!Y-m-d\TH:i:s.u',
        ];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, $utc);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->format('Y-m-d\TH:i:sP');
            }
        }

        return null;
    }
}
