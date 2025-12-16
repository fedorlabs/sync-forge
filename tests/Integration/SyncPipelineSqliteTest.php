<?php

declare(strict_types=1);

namespace SyncForge\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;
use SyncForge\Bridge\DoctrineDbal\DbalExistingRowsProvider;
use SyncForge\Bridge\DoctrineDbal\DbalFallbackBatchExecutor;
use SyncForge\Bridge\DoctrineDbal\DbalMySqlBulkExecutor;
use SyncForge\Bridge\DoctrineDbal\DbalPlatformDetector;
use SyncForge\Bridge\DoctrineDbal\DbalPostgresBulkExecutor;
use SyncForge\Executor\BulkExecutorFactory;
use SyncForge\Key\CompositeKeyResolver;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Metadata\InMemoryEntityMetadataProvider;
use SyncForge\SyncForge;

final class SyncPipelineSqliteTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testFullSyncWithDeleteMissing(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->createSchema($connection);

        $syncForge = $this->buildSyncForge($connection);

        $rowsV1 = [
            ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100],
            ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200],
        ];

        $resultV1 = $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($rowsV1)
            ->chunkSize(1)
            ->run();

        self::assertSame(2, $resultV1->inserted);
        self::assertSame(0, $resultV1->updated);
        self::assertSame(0, $resultV1->deleted);

        $rowsV2 = [
            ['external_id' => 'A-1', 'name' => 'Alpha+', 'price' => 150],
            ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300],
        ];

        $resultV2 = $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($rowsV2)
            ->deleteMissing(true)
            ->chunkSize(2)
            ->run();

        self::assertSame(1, $resultV2->inserted);
        self::assertSame(1, $resultV2->updated);
        self::assertSame(1, $resultV2->deleted);

        $all = $connection->createQueryBuilder()
            ->select('external_id', 'name', 'price')
            ->from('products')
            ->orderBy('external_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame([
            ['external_id' => 'A-1', 'name' => 'Alpha+', 'price' => 150],
            ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300],
        ], $all);
    }

    /**
     * @throws Exception
     */
    public function testDryRunDoesNotWriteData(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->createSchema($connection);

        $connection->insert('products', ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100]);
        $connection->insert('products', ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200]);

        $syncForge = $this->buildSyncForge($connection);

        $rows = [
            ['external_id' => 'A-1', 'name' => 'Alpha changed', 'price' => 110],
            ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300],
        ];

        $result = $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($rows)
            ->deleteMissing(true)
            ->dryRun(true)
            ->run();

        self::assertTrue($result->dryRun);
        self::assertSame(1, $result->inserted);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->deleted);

        $all = $connection->createQueryBuilder()
            ->select('external_id', 'name', 'price')
            ->from('products')
            ->orderBy('external_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame([
            ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100],
            ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200],
        ], $all);
    }

    /**
     * @throws Exception
     */
    public function testLargeSingleKeyChunkSync(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->createSchema($connection);
        $syncForge = $this->buildSyncForge($connection);

        $initialRows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $initialRows[] = [
                'external_id' => sprintf('SKU-%04d', $i),
                'name' => 'Name ' . $i,
                'price' => 100 + ($i % 50),
            ];
        }

        $resultV1 = $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($initialRows)
            ->chunkSize(1000)
            ->run();

        self::assertSame(1000, $resultV1->inserted);

        $nextRows = [];
        for ($i = 1; $i <= 800; $i++) {
            $nextRows[] = [
                'external_id' => sprintf('SKU-%04d', $i),
                'name' => 'Name ' . $i,
                'price' => $i <= 200 ? 999 : 100 + ($i % 50),
            ];
        }
        for ($i = 1001; $i <= 1200; $i++) {
            $nextRows[] = [
                'external_id' => sprintf('SKU-%04d', $i),
                'name' => 'Name ' . $i,
                'price' => 150,
            ];
        }

        $resultV2 = $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($nextRows)
            ->chunkSize(1000)
            ->deleteMissing(true)
            ->run();

        self::assertSame(200, $resultV2->inserted);
        self::assertSame(200, $resultV2->updated);
        self::assertSame(200, $resultV2->deleted);
    }

    private function buildSyncForge(Connection $connection): SyncForge
    {
        $metadata = new EntityMetadata(
            entityClass: SqliteProductStub::class,
            tableName: 'products',
            fields: ['external_id', 'name', 'price'],
            fieldToColumn: ['external_id' => 'external_id', 'name' => 'name', 'price' => 'price'],
            identifierFields: ['external_id'],
            updatableFields: ['name', 'price'],
        );

        $metadataProvider = new InMemoryEntityMetadataProvider([
            SqliteProductStub::class => $metadata,
        ]);

        $keyResolver = new CompositeKeyResolver();

        return new SyncForge(
            metadataProvider: $metadataProvider,
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([
                new DbalPostgresBulkExecutor($connection),
                new DbalMySqlBulkExecutor($connection),
                new DbalFallbackBatchExecutor($connection),
            ]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );
    }

    /**
     * @throws Exception
     */
    private function createSchema(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE products (external_id VARCHAR(64) PRIMARY KEY, name VARCHAR(255) NOT NULL, price INTEGER NOT NULL)',
        );
    }
}

final class SqliteProductStub
{
}
