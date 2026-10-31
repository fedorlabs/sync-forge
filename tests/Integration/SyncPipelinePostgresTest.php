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

final class SyncPipelinePostgresTest extends TestCase
{
    public function testIntegerSourceKeyMatchesStringDatabaseKey(): void
    {
        $connection = $this->connection;
        self::assertNotNull($connection);
        $connection->insert('products', ['external_id' => '1', 'name' => 'Old', 'price' => 1]);

        $result = $this->buildSyncForge($connection)->for(PostgresProductStub::class)
            ->key(['external_id'])->source([['external_id' => 1, 'name' => 'New', 'price' => 1]])
            ->deleteMissing()->run();

        self::assertSame(0, $result->inserted);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->deleted);
        self::assertSame('New', $connection->fetchOne("SELECT name FROM products WHERE external_id = '1'"));
    }

    public function testDeleteMissingAcrossMultipleBatchesMatchesDryRun(): void
    {
        $connection = $this->connection;
        self::assertNotNull($connection);
        foreach (range(1, 6) as $number) {
            $connection->insert('products', ['external_id' => (string) $number, 'name' => 'Product', 'price' => 1]);
        }
        $sync = $this->buildSyncForge($connection);
        $source = [['external_id' => '6', 'name' => 'Product', 'price' => 1]];
        $preview = $sync->for(PostgresProductStub::class)->key(['external_id'])->source($source)
            ->chunkSize(2)->deleteMissing()->dryRun()->run();
        self::assertSame(['1', '2', '3', '4', '5', '6'], $connection->fetchFirstColumn('SELECT external_id FROM products ORDER BY external_id'));
        $result = $sync->for(PostgresProductStub::class)->key(['external_id'])->source($source)
            ->chunkSize(2)->deleteMissing()->run();

        self::assertSame(5, $preview->deleted);
        self::assertSame($preview->deleted, $result->deleted);
        self::assertSame(['6'], $connection->fetchFirstColumn('SELECT external_id FROM products'));
    }

    public function testInsertRowsWithDifferentFieldsKeepsDefaultsAndValues(): void
    {
        $connection = $this->connection;
        self::assertNotNull($connection);
        $connection->executeStatement('ALTER TABLE products ALTER COLUMN price SET DEFAULT 42');

        $result = $this->buildSyncForge($connection)->for(PostgresProductStub::class)
            ->key(['external_id'])
            ->source([
                ['external_id' => 'A', 'name' => 'First'],
                ['external_id' => 'B', 'name' => 'Second', 'price' => 99],
                ['external_id' => 'C', 'name' => 'Third'],
            ])->run();

        self::assertSame(3, $result->inserted);
        self::assertSame([42, 99, 42], array_map('intval', $connection->fetchFirstColumn('SELECT price FROM products ORDER BY external_id')));
    }

    private ?Connection $connection = null;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $dsn = getenv('TEST_PG_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set TEST_PG_DSN to run PostgreSQL integration tests.');
        }

        $parts = parse_url($dsn);
        if ($parts === false) {
            self::fail('Invalid TEST_PG_DSN format.');
        }

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 5432,
            'dbname' => isset($parts['path']) ? ltrim($parts['path'], '/') : 'sync_forge_test',
            'user' => $parts['user'] ?? 'sync_forge',
            'password' => $parts['pass'] ?? 'sync_forge',
        ]);

        $this->connection->executeStatement('DROP TABLE IF EXISTS products');
        $this->connection->executeStatement('CREATE TABLE products (external_id VARCHAR(64) PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL)');
    }

    /**
     * @throws Exception
     */
    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS products');
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * @throws Exception
     */
    public function testPostgresExecutorUpsertAndDelete(): void
    {
        $connection = $this->connection;
        self::assertNotNull($connection);

        $syncForge = $this->buildSyncForge($connection);

        $resultV1 = $syncForge->for(PostgresProductStub::class)
            ->key(['external_id'])
            ->source([
                ['external_id' => 'A-1', 'name' => 'Alpha', 'price' => 100],
                ['external_id' => 'B-1', 'name' => 'Beta', 'price' => 200],
            ])
            ->run();

        self::assertSame(2, $resultV1->inserted);

        $resultV2 = $syncForge->for(PostgresProductStub::class)
            ->key(['external_id'])
            ->source([
                ['external_id' => 'A-1', 'name' => 'Alpha+', 'price' => 150],
                ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300],
            ])
            ->deleteMissing(true)
            ->run();

        self::assertSame(1, $resultV2->inserted);
        self::assertSame(1, $resultV2->updated);
        self::assertSame(1, $resultV2->deleted);

        $rows = $connection->createQueryBuilder()
            ->select('external_id', 'name', 'price')
            ->from('products')
            ->orderBy('external_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertSame([
            ['external_id' => 'A-1', 'name' => 'Alpha+', 'price' => 150],
            ['external_id' => 'C-1', 'name' => 'Gamma', 'price' => 300],
        ], $this->normalizeRows($rows));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'external_id' => (string) $row['external_id'],
            'name' => (string) $row['name'],
            'price' => (int) $row['price'],
        ], $rows);
    }

    private function buildSyncForge(Connection $connection): SyncForge
    {
        $metadata = new EntityMetadata(
            entityClass: PostgresProductStub::class,
            tableName: 'products',
            fields: ['external_id', 'name', 'price'],
            fieldToColumn: ['external_id' => 'external_id', 'name' => 'name', 'price' => 'price'],
            identifierFields: ['external_id'],
            updatableFields: ['name', 'price'],
        );

        $metadataProvider = new InMemoryEntityMetadataProvider([
            PostgresProductStub::class => $metadata,
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
}

final class PostgresProductStub
{
}
