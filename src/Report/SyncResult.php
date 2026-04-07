<?php

declare(strict_types=1);

namespace SyncForge\Report;

use DateTimeImmutable;
use DateTimeInterface;

final class SyncResult
{
    /**
     * @param list<string> $warnings
     * @param list<ErrorEntry> $errors
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly DateTimeImmutable $startedAt,
        public readonly DateTimeImmutable $finishedAt,
        public readonly int $processedRows,
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $deleted,
        public readonly int $unchanged,
        public readonly int $chunkCount,
        public readonly bool $dryRun,
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'entityClass' => $this->entityClass,
            'startedAt' => $this->startedAt->format(DateTimeInterface::ATOM),
            'finishedAt' => $this->finishedAt->format(DateTimeInterface::ATOM),
            'durationMs' => $this->calculateDurationMs(),
            'processedRows' => $this->processedRows,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'unchanged' => $this->unchanged,
            'chunkCount' => $this->chunkCount,
            'dryRun' => $this->dryRun,
            'warnings' => $this->warnings,
            'errors' => array_map(
                static fn (ErrorEntry $e): array => [
                    'type' => $e->type,
                    'message' => $e->message,
                    'chunkIndex' => $e->chunkIndex,
                ],
                $this->errors,
            ),
        ];
    }

    private function calculateDurationMs(): int
    {
        $start = (float) $this->startedAt->format('U.u');
        $end = (float) $this->finishedAt->format('U.u');

        return (int) round(max(0.0, ($end - $start) * 1000));
    }
}
