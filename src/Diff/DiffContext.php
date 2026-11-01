<?php

declare(strict_types=1);

namespace SyncForge\Diff;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use SyncForge\Metadata\EntityMetadata;

final class DiffContext
{
    /**
     * @param list<string> $keyFields
     */
    public function __construct(
        public readonly EntityMetadata $metadata,
        public readonly array $keyFields,
        public readonly ?AbstractPlatform $platform = null,
    ) {
    }
}
