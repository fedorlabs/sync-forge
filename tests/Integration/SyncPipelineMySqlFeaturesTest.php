<?php

declare(strict_types=1);

namespace SyncForge\Tests\Integration;

use Doctrine\DBAL\Connection;

final class SyncPipelineMySqlFeaturesTest extends AbstractExternalDatabaseFeaturesTestCase
{
    protected function openConnection(): Connection
    {
        return $this->connect('pdo_mysql', 'TEST_MYSQL_DSN', 3306);
    }

    protected function jsonColumnType(): string
    {
        return 'JSON';
    }

    protected function generatedIdColumnSql(): string
    {
        return 'id INT AUTO_INCREMENT PRIMARY KEY';
    }

    protected function dateTimeColumnSql(): string
    {
        return 'DATETIME';
    }

    protected function dateTimeDbalType(): string
    {
        return 'datetime_immutable';
    }
}
