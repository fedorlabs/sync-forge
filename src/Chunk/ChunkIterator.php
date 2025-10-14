<?php

declare(strict_types=1);

namespace SyncForge\Chunk;

use Generator;
use SyncForge\Exception\InvalidConfigurationException;

final class ChunkIterator
{
    /**
     * @param iterable<mixed> $rows
     * @return Generator<int, list<array<string,mixed>>>
     */
    public static function fromIterable(iterable $rows, int $size): Generator
    {
        if ($size < 1) {
            throw new InvalidConfigurationException('Chunk size must be greater than 0.');
        }

        $chunk = [];
        $chunkIndex = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidConfigurationException('Source rows must be arrays.');
            }

            $chunk[] = $row;
            if (count($chunk) >= $size) {
                yield $chunkIndex => $chunk;
                $chunk = [];
                $chunkIndex++;
            }
        }

        if ($chunk !== []) {
            yield $chunkIndex => $chunk;
        }
    }
}
