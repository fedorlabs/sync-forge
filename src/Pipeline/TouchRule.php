<?php

declare(strict_types=1);

namespace SyncForge\Pipeline;

/**
 * Sets a field on an updated row when one of the watched fields changed in the same update.
 */
final class TouchRule
{
    /**
     * @param list<string>|null $watchedFields null means any changed field
     * @param mixed $value a value, or a Closure evaluated once per run; null means the start of the run
     */
    public function __construct(
        public readonly string $field,
        public readonly ?array $watchedFields,
        public readonly mixed $value = null,
    ) {
    }
}
