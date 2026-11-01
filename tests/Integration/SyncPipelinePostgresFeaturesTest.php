<?php

declare(strict_types=1);

namespace SyncForge\Tests\Integration;

use Doctrine\DBAL\Connection;

final class SyncPipelinePostgresFeaturesTest extends AbstractExternalDatabaseFeaturesTestCase
{
    protected function openConnection(): Connection
    {
        return $this->connect('pdo_pgsql', 'TEST_PG_DSN', 5432);
    }

    protected function jsonColumnType(): string
    {
        return 'JSONB';
    }

    protected function generatedIdColumnSql(): string
    {
        return 'id SERIAL PRIMARY KEY';
    }

    protected function dateTimeColumnSql(): string
    {
        return 'TIMESTAMPTZ';
    }

    protected function booleanColumnSql(): string
    {
        return 'BOOLEAN';
    }

    protected function dateTimeDbalType(): string
    {
        return 'datetimetz_immutable';
    }
}
