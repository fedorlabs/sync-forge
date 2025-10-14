<?php

declare(strict_types=1);

namespace SyncForge\Key;

use SyncForge\Exception\InvalidConfigurationException;

final class CompositeKeyResolver implements KeyResolverInterface
{
    public function indexByKey(array $rows, array $keyFields): KeyIndex
    {
        $rowsByKey = [];
        $duplicates = 0;

        foreach ($rows as $row) {
            $key = $this->makeKey($row, $keyFields);
            if (isset($rowsByKey[$key])) {
                $duplicates++;
            }
            $rowsByKey[$key] = $row;
        }

        return new KeyIndex($rowsByKey, $duplicates);
    }

    /**
     * @throws \JsonException
     */
    public function makeKey(array $row, array $keyFields): string
    {
        if ($keyFields === []) {
            throw new InvalidConfigurationException('Key fields cannot be empty.');
        }

        $parts = [];

        foreach ($keyFields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidConfigurationException(sprintf('Missing key field "%s" in source row.', $field));
            }

            $value = $row[$field];
            $encoded = $this->encodeValue($value);
            $parts[] = strlen($field) . ':' . $field . '=' . strlen($encoded) . ':' . $encoded;
        }

        return implode('|', $parts);
    }

    /**
     * @throws \JsonException
     */
    private function encodeValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'n:null',
            is_bool($value) => 'b:' . ($value ? '1' : '0'),
            is_int($value) => 'i:' . (string) $value,
            is_float($value) => 'f:' . (string) $value,
            is_string($value) => 's:' . $value,
            $value instanceof \DateTimeInterface => 'd:' . $value->format(\DateTimeInterface::ATOM),
            default => 'j:' . json_encode($value, JSON_THROW_ON_ERROR),
        };
    }
}
