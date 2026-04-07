<?php

declare(strict_types=1);

namespace SyncForge\Report;

final class ErrorEntry
{
    public const string TYPE_VALIDATION = 'validation';
    public const string TYPE_DB = 'db';
    public const string TYPE_PIPELINE = 'pipeline';

    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly ?int $chunkIndex = null,
    ) {
    }
}
