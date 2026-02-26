<?php

declare(strict_types=1);

namespace SyncForge\Key;

interface KeyResolverInterface
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $keyFields
     */
    public function indexByKey(array $rows, array $keyFields): KeyIndex;

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keyFields
     */
    public function makeKey(array $row, array $keyFields): string;
}
