<?php

declare(strict_types=1);

namespace SyncForge\Tests\Support;

final class Money
{
    public function __construct(
        public readonly int $cents,
    ) {
    }
}
