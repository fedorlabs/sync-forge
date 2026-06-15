<?php

declare(strict_types=1);

namespace SyncForge\Report;

final class ErrorEntry
{
    public const TYPE_VALIDATION = 'validation';
    public const TYPE_DB = 'db';
    public const TYPE_PIPELINE = 'pipeline';

    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly ?int $chunkIndex = null,
    ) {
    }
}
