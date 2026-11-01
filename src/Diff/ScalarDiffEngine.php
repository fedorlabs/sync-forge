<?php

declare(strict_types=1);

namespace SyncForge\Diff;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Exception;
use SyncForge\Executor\ParameterValue;

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

            $type = $context->metadata->fieldTypes[$field] ?? null;
            $incomingValue = $incomingRow[$field];
            $existingValue = $existingRow[$field] ?? null;
            if ($incomingValue instanceof BackedEnum) {
                $incomingValue = $incomingValue->value;
            }

            if ($this->needsTypeConversion($incomingValue, $type, $context)) {
                \assert($context->platform !== null && $type !== null);
                [$incoming, $existing] = $this->normalizeConverted(
                    ParameterValue::normalize($incomingValue, $context->platform, $type),
                    $existingValue,
                    $type,
                );
            } else {
                $incoming = $this->normalize($incomingValue, $type);
                $existing = $this->normalize($existingValue, $type);
                if ($type === Types::JSON && is_string($incoming) && is_string($existing)) {
                    $incoming = $this->canonicalJson($incoming);
                    $existing = $this->canonicalJson($existing);
                }
            }

            if ($incoming !== $existing) {
                $changes[$field] = $incomingRow[$field];
            }
        }

        return $changes;
    }

    private function needsTypeConversion(mixed $value, ?string $type, DiffContext $context): bool
    {
        return (is_array($value) || (is_object($value) && !$value instanceof DateTimeInterface))
            && $type !== null
            && $context->platform !== null
            && Type::hasType($type);
    }

    /**
     * The incoming value is already in database form; the existing one is the raw database value.
     * JSON is compared structurally, other scalars as strings because drivers return numbers as strings.
     *
     * @return array{mixed,mixed}
     */
    private function normalizeConverted(mixed $converted, mixed $existing, string $type): array
    {
        if ($type === Types::JSON && is_string($converted) && is_string($existing)) {
            return [$this->canonicalJson($converted), $this->canonicalJson($existing)];
        }

        if (is_scalar($converted) && is_scalar($existing)) {
            return [(string) $converted, (string) $existing];
        }

        return [$converted, $existing];
    }

    /**
     * Re-encodes JSON with sorted object keys, so key order and whitespace do not cause false updates.
     */
    private function canonicalJson(string $json): string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }

        return json_encode($this->sortKeys($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sorted = array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        if (!array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    private function normalize(mixed $value, ?string $type): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $this->normalizeDateTime($value, $type);
        }

        if (is_string($value)) {
            $normalized = $this->normalizeDateString($value, $type);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $value;
    }

    /**
     * Mirrors what the executors write: date columns keep only the date, timezone-aware columns keep the instant,
     * and other columns keep the wall-clock time of the value, which the database later returns in the PHP timezone.
     */
    private function normalizeDateTime(DateTimeInterface $value, ?string $type): string
    {
        if ($this->isDateType($type)) {
            return $value->format('Y-m-d');
        }

        $immutable = DateTimeImmutable::createFromInterface($value);
        if ($this->isTimezoneAwareType($type)) {
            return $this->formatInstant($immutable);
        }

        $wallClock = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $immutable->format('Y-m-d H:i:s'),
            new DateTimeZone(date_default_timezone_get()),
        );

        return $this->formatInstant($wallClock instanceof DateTimeImmutable ? $wallClock : $immutable);
    }

    private function normalizeDateString(string $value, ?string $type): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        if ($this->isDateType($type)) {
            return substr($value, 0, 10);
        }

        $hasTimezone = preg_match('/\d{2}:\d{2}(:\d{2}(\.\d+)?)?\s*(Z|[+\-]\d{2}(:?\d{2})?)$/', $value) === 1;
        if ($hasTimezone) {
            try {
                return $this->formatInstant(new DateTimeImmutable($value));
            } catch (Exception) {
                return null;
            }
        }

        $formats = [
            '!Y-m-d H:i:s',
            '!Y-m-d H:i:s.u',
            '!Y-m-d\TH:i:s',
            '!Y-m-d\TH:i:s.u',
        ];
        $phpTimezone = new DateTimeZone(date_default_timezone_get());

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value, $phpTimezone);
            if ($dt instanceof DateTimeImmutable) {
                return $this->formatInstant($dt);
            }
        }

        return null;
    }

    private function formatInstant(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    private function isDateType(?string $type): bool
    {
        return $type === Types::DATE_MUTABLE || $type === Types::DATE_IMMUTABLE;
    }

    private function isTimezoneAwareType(?string $type): bool
    {
        return $type === Types::DATETIMETZ_MUTABLE || $type === Types::DATETIMETZ_IMMUTABLE;
    }
}
