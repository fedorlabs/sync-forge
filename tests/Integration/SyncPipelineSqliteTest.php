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
    public function testDeleteMissingScansEveryPageWhileDeleting(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        foreach (range(1, 6) as $number) {
            $connection->insert('products', ['external_id' => (string) $number, 'name' => 'Product', 'price' => 1]);
        }

        $result = $this->buildSyncForge($connection)->for(SqliteProductStub::class)
            ->key(['external_id'])->source([['external_id' => '6', 'name' => 'Product', 'price' => 1]])
            ->chunkSize(2)->deleteMissing()->run();

        self::assertSame(5, $result->deleted);
        self::assertSame(['6'], $connection->fetchFirstColumn('SELECT external_id FROM products'));
    }

    public function testCompositeKeyDeleteMissingPreservesOnlySourceRowsAcrossBatches(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE stock (sku TEXT NOT NULL, warehouse TEXT NOT NULL, PRIMARY KEY (sku, warehouse))');
        foreach (['A', 'B', 'C'] as $sku) {
            foreach (['1', '2'] as $warehouse) {
                $connection->insert('stock', ['sku' => $sku, 'warehouse' => $warehouse]);
            }
        }
        $metadata = new EntityMetadata(SqliteStockStub::class, 'stock', ['sku', 'warehouse'], ['sku' => 'sku', 'warehouse' => 'warehouse'], ['sku', 'warehouse'], []);
        $keyResolver = new CompositeKeyResolver();
        $sync = new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([SqliteStockStub::class => $metadata]),
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([new DbalFallbackBatchExecutor($connection)]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );
        $source = [['sku' => 'A', 'warehouse' => '2'], ['sku' => 'C', 'warehouse' => '1']];
        $result = $sync->for(SqliteStockStub::class)->key(['sku', 'warehouse'])->source($source)
            ->chunkSize(2)->deleteMissing()->run();

        self::assertSame(4, $result->deleted);
        self::assertSame($source, $connection->fetchAllAssociative('SELECT sku, warehouse FROM stock ORDER BY sku, warehouse'));
    }

    public function testDeleteMissingIsSkippedAfterFailedChunk(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $connection->insert('products', ['external_id' => 'keep', 'name' => 'Existing', 'price' => 1]);

        $result = $this->buildSyncForge($connection)->for(SqliteProductStub::class)
            ->key(['external_id'])->source([['external_id' => 'bad', 'name' => null, 'price' => 1]])
            ->continueOnError()->deleteMissing()->run();

        self::assertFalse($result->isSuccess());
        self::assertCount(1, $result->errors);
        self::assertContains('deleteMissing skipped because one or more chunks failed.', $result->warnings);
        self::assertSame(0, $result->deleted);
        self::assertSame(['keep'], $connection->fetchFirstColumn('SELECT external_id FROM products'));
    }

    public function testStringInputKeyMatchesIntegerDatabaseKey(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE products (external_id INTEGER PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL)');
        $connection->insert('products', ['external_id' => 1, 'name' => 'Old', 'price' => 1]);

        $result = $this->buildSyncForge($connection)->for(SqliteProductStub::class)
            ->key(['external_id'])->source([['external_id' => '1', 'name' => 'New', 'price' => 1]])
            ->deleteMissing()->run();

        self::assertSame(0, $result->inserted);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->deleted);
        self::assertSame('New', $connection->fetchOne('SELECT name FROM products WHERE external_id = 1'));
    }

    public function testAmbiguousDatabaseKeyFailsBeforeWritingOrDeleting(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE products (external_id INTEGER PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL)');
        $connection->insert('products', ['external_id' => 1, 'name' => 'Old', 'price' => 1]);
        $connection->insert('products', ['external_id' => 2, 'name' => 'Keep', 'price' => 1]);

        $result = $this->buildSyncForge($connection)->for(SqliteProductStub::class)
            ->key(['external_id'])->source([['external_id' => '001', 'name' => 'New', 'price' => 1]])
            ->continueOnError()->deleteMissing()->run();

        self::assertFalse($result->isSuccess());
        self::assertSame(0, $result->deleted);
        self::assertSame(['Old', 'Keep'], $connection->fetchFirstColumn('SELECT name FROM products ORDER BY external_id'));
    }

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

    /**
     * @throws Exception
     */
    public function testSecondRunWithSameSourceProducesNoChanges(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->createSchema($connection);

        $syncForge = $this->buildSyncForge($connection);

        $rows = [
            ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100],
            ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200],
            ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300],
        ];

        $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($rows)
            ->deleteMissing(true)
            ->run();

        $result = $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($rows)
            ->deleteMissing(true)
            ->run();

        self::assertSame(0, $result->inserted);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->deleted);
        self::assertSame(3, $result->unchanged);
    }

    /**
     * @throws Exception
     */
    public function testSyncIsStableAfterRoundTrip(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->createSchema($connection);

        $syncForge = $this->buildSyncForge($connection);

        $original = [
            ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100],
            ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200],
        ];

        $modified = [
            ['external_id' => 'A-1', 'name' => 'Alpha changed', 'price' => 150],
            ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200],
            ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300],
        ];

        $syncForge->for(SqliteProductStub::class)->key(['external_id'])->source($original)->run();

        $syncForge->for(SqliteProductStub::class)->key(['external_id'])->source($modified)->deleteMissing(true)->run();

        // sync back to original - should detect changes and undo them
        $result = $syncForge->for(SqliteProductStub::class)
            ->key(['external_id'])
            ->source($original)
            ->deleteMissing(true)
            ->run();

        self::assertSame(0, $result->inserted);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->deleted);
        self::assertSame(1, $result->unchanged);

        $all = $connection->createQueryBuilder()
            ->select('external_id', 'name', 'price')
            ->from('products')
            ->orderBy('external_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame($original, $all);
    }

    /**
     * @throws Exception
     */
    public function testCompositeKeyWithDeleteMissing(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $connection->executeStatement(
            'CREATE TABLE stock_levels (sku VARCHAR(64) NOT NULL, warehouse_code VARCHAR(16) NOT NULL, qty INTEGER NOT NULL, PRIMARY KEY (sku, warehouse_code))',
        );

        $connection->insert('stock_levels', ['sku' => 'SKU-A', 'warehouse_code' => 'WH-1', 'qty' => 10]);
        $connection->insert('stock_levels', ['sku' => 'SKU-A', 'warehouse_code' => 'WH-2', 'qty' => 20]);
        $connection->insert('stock_levels', ['sku' => 'SKU-B', 'warehouse_code' => 'WH-1', 'qty' => 30]);

        $metadata = new EntityMetadata(
            entityClass: SqliteStockStub::class,
            tableName: 'stock_levels',
            fields: ['sku', 'warehouse_code', 'qty'],
            fieldToColumn: ['sku' => 'sku', 'warehouse_code' => 'warehouse_code', 'qty' => 'qty'],
            identifierFields: ['sku', 'warehouse_code'],
            updatableFields: ['qty'],
        );

        $keyResolver = new CompositeKeyResolver();

        $syncForge = new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([SqliteStockStub::class => $metadata]),
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([
                new DbalPostgresBulkExecutor($connection),
                new DbalMySqlBulkExecutor($connection),
                new DbalFallbackBatchExecutor($connection),
            ]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );

        $incoming = [
            ['sku' => 'SKU-A', 'warehouse_code' => 'WH-1', 'qty' => 15],
            ['sku' => 'SKU-B', 'warehouse_code' => 'WH-1', 'qty' => 30],
            ['sku' => 'SKU-C', 'warehouse_code' => 'WH-1', 'qty' => 5],
        ];

        $result = $syncForge->for(SqliteStockStub::class)
            ->key(['sku', 'warehouse_code'])
            ->source($incoming)
            ->deleteMissing(true)
            ->run();

        self::assertSame(1, $result->inserted);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->deleted);
        self::assertSame(1, $result->unchanged);

        $all = $connection->createQueryBuilder()
            ->select('sku', 'warehouse_code', 'qty')
            ->from('stock_levels')
            ->orderBy('sku', 'ASC')
            ->addOrderBy('warehouse_code', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame([
            ['sku' => 'SKU-A', 'warehouse_code' => 'WH-1', 'qty' => 15],
            ['sku' => 'SKU-B', 'warehouse_code' => 'WH-1', 'qty' => 30],
            ['sku' => 'SKU-C', 'warehouse_code' => 'WH-1', 'qty' => 5],
        ], $all);
    }

    public function testDateTimeSourceValuesAreWrittenAsUtcStrings(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE events (external_id VARCHAR(64) PRIMARY KEY, happened_at TEXT NULL)');
        $metadata = new EntityMetadata(
            SqliteEventStub::class,
            'events',
            ['external_id', 'happened_at'],
            ['external_id' => 'external_id', 'happened_at' => 'happened_at'],
            ['external_id'],
            ['happened_at'],
        );
        $keyResolver = new CompositeKeyResolver();
        $sync = new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([SqliteEventStub::class => $metadata]),
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([new DbalFallbackBatchExecutor($connection)]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );
        $run = static fn (string $date) => $sync->for(SqliteEventStub::class)->key(['external_id'])
            ->source([['external_id' => 'a', 'happened_at' => new \DateTimeImmutable($date)]])->run();

        self::assertSame(1, $run('2024-01-01 12:00:00+03:00')->inserted);
        self::assertSame('2024-01-01 09:00:00.000000', $connection->fetchOne('SELECT happened_at FROM events'));
        self::assertSame(1, $run('2024-01-01 13:00:00+03:00')->updated);
        self::assertSame(1, $run('2024-01-01 13:00:00+03:00')->unchanged);
    }

    public function testNonExceptionErrorIsCollectedWithContinueOnError(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);

        $result = $this->buildSyncForge($connection)->for(SqliteProductStub::class)
            ->key(['external_id'])->source([['external_id' => 'a', 'name' => new \stdClass(), 'price' => 1]])
            ->continueOnError()->run();

        self::assertFalse($result->isSuccess());
        self::assertCount(1, $result->errors);
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

final class SqliteStockStub
{
}

final class SqliteEventStub
{
}
