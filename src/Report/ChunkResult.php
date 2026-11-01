<?php

declare(strict_types=1);

namespace SyncForge\Report;

/**
 * Progress report passed to the onChunk() callback after each chunk, successful or failed.
 */
final class ChunkResult
{
    public function __construct(
        public readonly int $chunkIndex,
        public readonly int $rows,
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $skipped,
        public readonly int $processedRows,
        public readonly ?ErrorEntry $error = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }
}
