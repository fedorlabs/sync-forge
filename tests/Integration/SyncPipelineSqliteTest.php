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
use SyncForge\Exception\InvalidConfigurationException;
use SyncForge\Exception\MetadataException;
use SyncForge\Executor\BulkExecutorFactory;
use SyncForge\Key\CompositeKeyResolver;
use SyncForge\Metadata\EntityMetadata;
use SyncForge\Metadata\InMemoryEntityMetadataProvider;
use SyncForge\Report\ChunkResult;
use SyncForge\Report\ErrorEntry;
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

    public function testDateTimeSourceValuesAreWrittenLikeDoctrineOrm(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Amsterdam');

        try {
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            $connection->executeStatement('CREATE TABLE events (external_id VARCHAR(64) PRIMARY KEY, happened_at TEXT NULL, day TEXT NULL, seen_at TEXT NULL)');
            $metadata = new EntityMetadata(
                SqliteEventStub::class,
                'events',
                ['external_id', 'happened_at', 'day', 'seen_at'],
                ['external_id' => 'external_id', 'happened_at' => 'happened_at', 'day' => 'day', 'seen_at' => 'seen_at'],
                ['external_id'],
                ['happened_at', 'day', 'seen_at'],
                ['happened_at' => 'datetime_immutable', 'day' => 'date_immutable', 'seen_at' => 'datetimetz_immutable'],
            );
            $keyResolver = new CompositeKeyResolver();
            $sync = new SyncForge(
                metadataProvider: new InMemoryEntityMetadataProvider([SqliteEventStub::class => $metadata]),
                keyResolver: $keyResolver,
                executorFactory: new BulkExecutorFactory([new DbalFallbackBatchExecutor($connection)]),
                platformDetector: new DbalPlatformDetector($connection),
                existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
            );
            $amsterdam = new \DateTimeZone('Europe/Amsterdam');
            $run = static fn (string $local) => $sync->for(SqliteEventStub::class)->key(['external_id'])
                ->source([[
                    'external_id' => 'a',
                    'happened_at' => new \DateTimeImmutable($local, $amsterdam),
                    'day' => new \DateTimeImmutable($local, $amsterdam),
                    'seen_at' => new \DateTimeImmutable($local, $amsterdam),
                ]])->run();

            self::assertSame(1, $run('2024-06-01 00:30:00')->inserted);
            self::assertSame(
                ['happened_at' => '2024-06-01 00:30:00', 'day' => '2024-06-01', 'seen_at' => '2024-06-01 00:30:00'],
                $connection->fetchAssociative('SELECT happened_at, day, seen_at FROM events'),
            );
            self::assertSame(1, $run('2024-06-01 00:30:00')->unchanged);
            self::assertSame(1, $run('2024-06-01 13:00:00')->updated);
            self::assertSame(1, $run('2024-06-01 13:00:00')->unchanged);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testReturnInsertedCollectsKeysAndGeneratedIdentifiers(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE parts (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL UNIQUE, price INTEGER NOT NULL)');
        $metadata = new EntityMetadata(
            SqlitePartStub::class,
            'parts',
            ['id', 'sku', 'price'],
            ['id' => 'id', 'sku' => 'sku', 'price' => 'price'],
            ['id'],
            ['price'],
        );
        $keyResolver = new CompositeKeyResolver();
        $sync = new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([SqlitePartStub::class => $metadata]),
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([new DbalFallbackBatchExecutor($connection)]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );
        $connection->insert('parts', ['sku' => 'A', 'price' => 1]);
        $source = [['sku' => 'A', 'price' => 1], ['sku' => 'B', 'price' => 2], ['sku' => 'C', 'price' => 3]];

        $dryRun = $sync->for(SqlitePartStub::class)->key(['sku'])->source($source)->returnInserted()->dryRun()->run();
        self::assertSame([['sku' => 'B'], ['sku' => 'C']], $dryRun->insertedKeys);

        $withoutOptIn = $sync->for(SqlitePartStub::class)->key(['sku'])->source([['sku' => 'D', 'price' => 4]])->run();
        self::assertSame([], $withoutOptIn->insertedKeys);

        $result = $sync->for(SqlitePartStub::class)->key(['sku'])->source($source)->chunkSize(1)->returnInserted()->run();
        self::assertSame(2, $result->inserted);
        self::assertSame([['sku' => 'B', 'id' => 3], ['sku' => 'C', 'id' => 4]], $result->insertedKeys);
        self::assertSame($result->insertedKeys, $result->toArray()['insertedKeys']);
    }

    public function testSetOnInsertTouchOnChangeAndReturnedKeys(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE stocks (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL UNIQUE, price INTEGER NOT NULL, qty INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, price_updated_at TEXT NULL)');
        $metadata = new EntityMetadata(
            SqliteStockLevelStub::class,
            'stocks',
            ['id', 'sku', 'price', 'qty', 'created_at', 'updated_at', 'price_updated_at'],
            ['id' => 'id', 'sku' => 'sku', 'price' => 'price', 'qty' => 'qty', 'created_at' => 'created_at', 'updated_at' => 'updated_at', 'price_updated_at' => 'price_updated_at'],
            ['id'],
            ['price', 'qty'],
            ['created_at' => 'datetime_immutable', 'updated_at' => 'datetime_immutable', 'price_updated_at' => 'datetime_immutable'],
        );
        $keyResolver = new CompositeKeyResolver();
        $sync = new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([SqliteStockLevelStub::class => $metadata]),
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([new DbalFallbackBatchExecutor($connection)]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );
        $run = static fn (array $rows, bool $deleteMissing = false) => $sync->for(SqliteStockLevelStub::class)->key(['sku'])
            ->source($rows)
            ->setOnInsert(['created_at' => static fn (): \DateTimeImmutable => new \DateTimeImmutable('2024-06-01 10:00:00'), 'updated_at' => new \DateTimeImmutable('2024-06-01 10:00:00')])
            ->touchOnChange('updated_at', '*', new \DateTimeImmutable('2024-06-02 11:00:00'))
            ->touchOnChange('price_updated_at', ['price'], new \DateTimeImmutable('2024-06-02 11:00:00'))
            ->returnInserted()->returnUpdated()->returnDeleted()
            ->deleteMissing($deleteMissing)->run();

        $first = $run([['sku' => 'A', 'price' => 10, 'qty' => 1], ['sku' => 'B', 'price' => 20, 'qty' => 2], ['sku' => 'C', 'price' => 30, 'qty' => 3]]);
        self::assertSame(3, $first->inserted);
        self::assertSame('2024-06-01 10:00:00', $connection->fetchOne("SELECT created_at FROM stocks WHERE sku = 'A'"));
        self::assertNull($connection->fetchOne("SELECT price_updated_at FROM stocks WHERE sku = 'A'"));

        $second = $run([['sku' => 'A', 'price' => 10, 'qty' => 1], ['sku' => 'B', 'price' => 25, 'qty' => 2], ['sku' => 'C', 'price' => 30, 'qty' => 9], ['sku' => 'D', 'price' => 40, 'qty' => 4]]);
        self::assertSame(1, $second->inserted);
        self::assertSame(2, $second->updated);
        self::assertSame(1, $second->unchanged);
        $stamps = [];
        foreach ($connection->fetchAllAssociative('SELECT sku, updated_at, price_updated_at FROM stocks ORDER BY sku') as $row) {
            $stamps[$row['sku']] = [$row['updated_at'], $row['price_updated_at']];
        }
        self::assertSame(
            ['A' => ['2024-06-01 10:00:00', null], 'B' => ['2024-06-02 11:00:00', '2024-06-02 11:00:00'], 'C' => ['2024-06-02 11:00:00', null], 'D' => ['2024-06-01 10:00:00', null]],
            $stamps,
        );
        self::assertSame([['sku' => 'D', 'id' => 4]], $second->insertedKeys);
        self::assertSame([['sku' => 'B', 'id' => 2], ['sku' => 'C', 'id' => 3]], $second->updatedKeys);

        $third = $run([['sku' => 'A', 'price' => 10, 'qty' => 1], ['sku' => 'D', 'price' => 40, 'qty' => 4]], deleteMissing: true);
        self::assertSame(2, $third->deleted);
        self::assertSame([['sku' => 'B'], ['sku' => 'C']], $third->deletedKeys);
    }

    public function testWriteTimeFieldOutsideMetadataIsRejected(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildPriceSync($connection);

        $this->expectException(MetadataException::class);
        $this->expectExceptionMessage('"no_such_field" is not mapped');

        $sync->for(SqliteScopedPriceStub::class)->key(['supplier_id', 'sku'])
            ->source([['supplier_id' => 1, 'sku' => 'A', 'price' => 1]])->touchOnChange('no_such_field')->run();
    }

    public function testUpdateFieldsModesAndProgressCallback(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildCatalogSync($connection);
        $connection->insert('catalog', ['sku' => 'A', 'name' => 'Old A', 'price' => 10, 'is_active' => 1]);
        $connection->insert('catalog', ['sku' => 'B', 'name' => 'Old B', 'price' => 20, 'is_active' => 1]);
        $source = [
            ['sku' => 'A', 'name' => 'New A', 'price' => 11, 'is_active' => true],
            ['sku' => 'B', 'name' => 'Old B', 'price' => 20, 'is_active' => true],
            ['sku' => 'C', 'name' => 'New C', 'price' => 30, 'is_active' => true],
        ];

        $priceOnly = $sync->for(SqliteCatalogStub::class)->key(['sku'])->source($source)
            ->updateFields(['price'])->insertMissing(false)->returnUpdated()->run();
        self::assertSame(0, $priceOnly->inserted);
        self::assertSame(1, $priceOnly->updated);
        self::assertSame(1, $priceOnly->skipped);
        self::assertSame(1, $priceOnly->unchanged);
        self::assertSame([['sku' => 'A']], $priceOnly->updatedKeys);
        self::assertSame(['name' => 'Old A', 'price' => 11], $connection->fetchAssociative("SELECT name, price FROM catalog WHERE sku = 'A'"));

        $progress = [];
        $addOnly = $sync->for(SqliteCatalogStub::class)->key(['sku'])->source($source)->chunkSize(2)
            ->updateExisting(false)
            ->onChunk(static function (ChunkResult $chunk) use (&$progress): void {
                $progress[] = [$chunk->chunkIndex, $chunk->rows, $chunk->inserted, $chunk->updated, $chunk->skipped, $chunk->processedRows];
            })->run();
        self::assertSame(1, $addOnly->inserted);
        self::assertSame(1, $addOnly->skipped);
        self::assertSame([[0, 2, 0, 0, 1, 2], [1, 1, 1, 0, 0, 3]], $progress);
        self::assertSame('Old A', $connection->fetchOne("SELECT name FROM catalog WHERE sku = 'A'"));
    }

    public function testProgressCallbackReportsFailedChunks(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildCatalogSync($connection);
        $reports = [];

        $result = $sync->for(SqliteCatalogStub::class)->key(['sku'])->chunkSize(1)->continueOnError()
            ->source([['sku' => 'A', 'name' => 'A', 'price' => 1, 'is_active' => true], ['sku' => 'B', 'name' => null, 'price' => 1, 'is_active' => true]])
            ->onChunk(static function (ChunkResult $chunk) use (&$reports): void {
                $reports[] = $chunk->isSuccess();
            })->run();

        self::assertFalse($result->isSuccess());
        self::assertSame([true, false], $reports);
    }

    public function testMarkMissingFlagsRowsInsteadOfDeletingThem(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildCatalogSync($connection);
        foreach ([['A', 1], ['B', 1], ['C', 0], ['D', 1]] as [$sku, $active]) {
            $connection->insert('catalog', ['sku' => $sku, 'name' => 'n', 'price' => 1, 'is_active' => $active, 'updated_at' => 'old']);
        }
        $run = static fn (array $skus, bool $dryRun = false) => $sync->for(SqliteCatalogStub::class)->key(['sku'])
            ->source(array_map(static fn (string $sku): array => ['sku' => $sku, 'name' => 'n', 'price' => 1, 'is_active' => true], $skus))
            ->markMissing(['is_active' => false])
            ->touchOnChange('updated_at', '*', 'now')
            ->returnDeleted()->chunkSize(2)->dryRun($dryRun)->run();

        $preview = $run(['A'], dryRun: true);
        self::assertSame(2, $preview->marked);
        self::assertSame(['A', 'B', 'D'], array_map('strval', $connection->fetchFirstColumn('SELECT sku FROM catalog WHERE is_active = 1 ORDER BY sku')));

        $result = $run(['A']);
        self::assertSame(0, $result->deleted);
        self::assertSame(2, $result->marked);
        self::assertSame([['sku' => 'B'], ['sku' => 'D']], array_map(static fn (array $key): array => ['sku' => $key['sku']], $result->markedKeys));
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM catalog'));
        self::assertSame(
            ['A' => [1, 'old'], 'B' => [0, 'now'], 'C' => [0, 'old'], 'D' => [0, 'now']],
            $this->stateBySku($connection),
        );

        $again = $run(['A']);
        self::assertSame(0, $again->marked);
        self::assertSame(1, $again->unchanged);
    }

    public function testMarkMissingIsBlockedForEmptySourceAndKeyFieldsCannotBeUpdateFields(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildCatalogSync($connection);

        try {
            $sync->for(SqliteCatalogStub::class)->key(['sku'])->source([])->markMissing(['is_active' => false])->run();
            self::fail('An empty source must block markMissing.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('markMissing with an empty source is blocked', $e->getMessage());
        }

        $this->expectException(InvalidConfigurationException::class);
        $sync->for(SqliteCatalogStub::class)->key(['sku'])->source([['sku' => 'A']])->updateFields(['sku'])->run();
    }

    /**
     * @return array<string,array{int,string}>
     */
    private function stateBySku(Connection $connection): array
    {
        $state = [];
        foreach ($connection->fetchAllAssociative('SELECT sku, is_active, updated_at FROM catalog ORDER BY sku') as $row) {
            $state[$row['sku']] = [(int) $row['is_active'], $row['updated_at']];
        }

        return $state;
    }

    private function buildCatalogSync(Connection $connection): SyncForge
    {
        $connection->executeStatement('CREATE TABLE catalog (sku TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, updated_at TEXT NULL)');
        $metadata = new EntityMetadata(
            SqliteCatalogStub::class,
            'catalog',
            ['sku', 'name', 'price', 'is_active', 'updated_at'],
            ['sku' => 'sku', 'name' => 'name', 'price' => 'price', 'is_active' => 'is_active', 'updated_at' => 'updated_at'],
            ['sku'],
            ['name', 'price', 'is_active'],
            ['is_active' => 'boolean', 'price' => 'integer'],
        );
        $keyResolver = new CompositeKeyResolver();

        return new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([SqliteCatalogStub::class => $metadata]),
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([new DbalFallbackBatchExecutor($connection)]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );
    }

    public function testScopeLimitsDeleteMissingToOneSupplier(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildPriceSync($connection);
        $connection->insert('prices', ['sku' => 'A', 'supplier_id' => 42, 'price' => 1]);
        $connection->insert('prices', ['sku' => 'B', 'supplier_id' => 42, 'price' => 1]);
        $connection->insert('prices', ['sku' => 'A', 'supplier_id' => 43, 'price' => 7]);
        $connection->insert('prices', ['sku' => 'C', 'supplier_id' => 43, 'price' => 1]);

        $result = $sync->for(SqliteScopedPriceStub::class)->key(['supplier_id', 'sku'])
            ->scope(['supplier_id' => 42])
            ->source([['sku' => 'A', 'price' => 2], ['sku' => 'D', 'price' => 3]])
            ->chunkSize(1)->deleteMissing()->run();

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $result->inserted);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->deleted);
        self::assertSame(
            [[42, 'A', 2], [42, 'D', 3], [43, 'A', 7], [43, 'C', 1]],
            array_map(
                static fn (array $row): array => [(int) $row['supplier_id'], $row['sku'], (int) $row['price']],
                $connection->fetchAllAssociative('SELECT supplier_id, sku, price FROM prices ORDER BY supplier_id, sku'),
            ),
        );
    }

    public function testScopeFieldMissingFromKeyIsRejectedBeforeAnyWrite(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildPriceSync($connection);
        $connection->insert('prices', ['sku' => 'X', 'supplier_id' => 7, 'price' => 100]);
        $connection->insert('prices', ['sku' => 'Y', 'supplier_id' => 42, 'price' => 200]);

        try {
            $sync->for(SqliteScopedPriceStub::class)->key(['sku'])
                ->scope(['supplier_id' => 42])
                ->source([['sku' => 'X', 'price' => 999]])->deleteMissing()->run();
            self::fail('A scope field outside the key must be rejected.');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('must be part of the sync key', $e->getMessage());
        }

        self::assertSame(
            [['X', 7, 100], ['Y', 42, 200]],
            array_map('array_values', $connection->fetchAllAssociative('SELECT sku, supplier_id, price FROM prices ORDER BY sku')),
        );
    }

    public function testRowOutsideScopeIsRejected(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildPriceSync($connection);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('outside the sync scope');

        $sync->for(SqliteScopedPriceStub::class)->key(['supplier_id', 'sku'])
            ->scope(['supplier_id' => 42])
            ->source([['sku' => 'A', 'supplier_id' => 43, 'price' => 1]])->run();
    }

    public function testScopeWithUnmappedFieldFails(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $sync = $this->buildPriceSync($connection);

        $this->expectException(MetadataException::class);

        $sync->for(SqliteScopedPriceStub::class)->key(['supplier_id', 'sku'])->scope(['unknown' => 1])->source([['sku' => 'A', 'price' => 1]])->run();
    }

    private function buildPriceSync(Connection $connection): SyncForge
    {
        $connection->executeStatement('CREATE TABLE prices (sku TEXT NOT NULL, supplier_id INTEGER NOT NULL, price INTEGER NOT NULL, PRIMARY KEY (supplier_id, sku))');
        $metadata = new EntityMetadata(
            SqliteScopedPriceStub::class,
            'prices',
            ['sku', 'supplier_id', 'price'],
            ['sku' => 'sku', 'supplier_id' => 'supplier_id', 'price' => 'price'],
            ['supplier_id', 'sku'],
            ['price'],
        );
        $keyResolver = new CompositeKeyResolver();

        return new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([SqliteScopedPriceStub::class => $metadata]),
            keyResolver: $keyResolver,
            executorFactory: new BulkExecutorFactory([new DbalFallbackBatchExecutor($connection)]),
            platformDetector: new DbalPlatformDetector($connection),
            existingRowsProvider: new DbalExistingRowsProvider($connection, $keyResolver),
        );
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
        self::assertSame(ErrorEntry::TYPE_INTERNAL, $result->errors[0]->type);
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

final class SqlitePartStub
{
}

final class SqliteStockLevelStub
{
}

final class SqliteCatalogStub
{
}

final class SqliteItemStub
{
}

final class SqliteScopedPriceStub
{
}

final class SqliteEventStub
{
}
