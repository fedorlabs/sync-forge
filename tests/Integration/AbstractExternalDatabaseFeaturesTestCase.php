<?php

declare(strict_types=1);

namespace SyncForge\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
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
use SyncForge\Tests\Support\Money;
use SyncForge\Tests\Support\MoneyType;

/**
 * Feature coverage (scope, DBAL types, returnInserted, dates) on a real PostgreSQL or MySQL server.
 */
abstract class AbstractExternalDatabaseFeaturesTestCase extends TestCase
{
    protected ?Connection $connection = null;

    private string $previousTimezone = 'UTC';

    abstract protected function openConnection(): Connection;

    abstract protected function jsonColumnType(): string;

    /** @return string primary key definition for an auto generated integer id column */
    abstract protected function generatedIdColumnSql(): string;

    /** @return string SQL type of a column that stores a timezone-aware instant, or a plain date-time */
    abstract protected function dateTimeColumnSql(): string;

    /** @return string DBAL type name matching dateTimeColumnSql() */
    abstract protected function dateTimeDbalType(): string;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        MoneyType::register();
        $this->previousTimezone = date_default_timezone_get();
        $this->connection = $this->openConnection();
        $this->dropTables();
    }

    /**
     * @throws Exception
     */
    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        $connection = $this->connection;
        if ($connection !== null) {
            $this->dropTables();
            $connection->close();
            $this->connection = null;
        }
    }

    /**
     * @throws Exception
     */
    public function testScopeLimitsLookupAndDeleteMissingToOneSupplier(): void
    {
        $connection = $this->requireConnection();
        $connection->executeStatement('CREATE TABLE sf_prices (supplier_id INT NOT NULL, sku VARCHAR(64) NOT NULL, price INT NOT NULL, PRIMARY KEY (supplier_id, sku))');
        foreach ([[42, 'A', 1], [42, 'B', 1], [43, 'A', 7], [43, 'C', 1]] as [$supplier, $sku, $price]) {
            $connection->insert('sf_prices', ['supplier_id' => $supplier, 'sku' => $sku, 'price' => $price]);
        }
        $sync = $this->buildSync(new EntityMetadata(
            ExternalPriceStub::class,
            'sf_prices',
            ['supplier_id', 'sku', 'price'],
            ['supplier_id' => 'supplier_id', 'sku' => 'sku', 'price' => 'price'],
            ['supplier_id', 'sku'],
            ['price'],
        ));

        $result = $sync->for(ExternalPriceStub::class)->key(['supplier_id', 'sku'])
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
                $connection->fetchAllAssociative('SELECT supplier_id, sku, price FROM sf_prices ORDER BY supplier_id, sku'),
            ),
        );
    }

    /**
     * @throws Exception
     */
    public function testJsonAndCustomTypeValuesAreConvertedAndCompareStably(): void
    {
        $connection = $this->requireConnection();
        $connection->executeStatement(sprintf('CREATE TABLE sf_items (sku VARCHAR(64) PRIMARY KEY, price INT NOT NULL, meta %s NULL)', $this->jsonColumnType()));
        $sync = $this->buildSync(new EntityMetadata(
            ExternalItemStub::class,
            'sf_items',
            ['sku', 'price', 'meta'],
            ['sku' => 'sku', 'price' => 'price', 'meta' => 'meta'],
            ['sku'],
            ['price', 'meta'],
            ['price' => MoneyType::NAME, 'meta' => 'json'],
        ));
        $run = static fn (int $cents, array $meta) => $sync->for(ExternalItemStub::class)->key(['sku'])
            ->source([['sku' => 'a', 'price' => new Money($cents), 'meta' => $meta]])->run();

        self::assertSame(1, $run(1050, ['b' => 2, 'a' => ['y' => 1, 'x' => 2]])->inserted);
        self::assertSame(1050, (int) $connection->fetchOne('SELECT price FROM sf_items'));
        self::assertSame(1, $run(1050, ['a' => ['x' => 2, 'y' => 1], 'b' => 2])->unchanged);
        self::assertSame(1, $run(1100, ['a' => ['x' => 2, 'y' => 1], 'b' => 2])->updated);
        self::assertSame(1, $run(1100, ['a' => ['x' => 3, 'y' => 1], 'b' => 2])->updated);
        self::assertSame(1, $run(1100, ['a' => ['x' => 3, 'y' => 1], 'b' => 2])->unchanged);
    }

    /**
     * @throws Exception
     */
    public function testReturnInsertedCollectsGeneratedIds(): void
    {
        $connection = $this->requireConnection();
        $connection->executeStatement(sprintf('CREATE TABLE sf_parts (%s, sku VARCHAR(64) NOT NULL UNIQUE, price INT NOT NULL)', $this->generatedIdColumnSql()));
        $connection->insert('sf_parts', ['sku' => 'A', 'price' => 1]);
        $sync = $this->buildSync(new EntityMetadata(
            ExternalPartStub::class,
            'sf_parts',
            ['id', 'sku', 'price'],
            ['id' => 'id', 'sku' => 'sku', 'price' => 'price'],
            ['id'],
            ['price'],
        ));

        $result = $sync->for(ExternalPartStub::class)->key(['sku'])
            ->source([['sku' => 'A', 'price' => 1], ['sku' => 'B', 'price' => 2], ['sku' => 'C', 'price' => 3]])
            ->chunkSize(2)->returnInserted()->run();

        self::assertSame(2, $result->inserted);
        $expected = $connection->fetchAllAssociative("SELECT sku, id FROM sf_parts WHERE sku IN ('B', 'C') ORDER BY sku");
        self::assertCount(2, $result->insertedKeys);
        self::assertSame(
            array_map(static fn (array $row): array => ['sku' => $row['sku'], 'id' => (int) $row['id']], $expected),
            array_map(static fn (array $row): array => ['sku' => $row['sku'], 'id' => (int) $row['id']], $result->insertedKeys),
        );
    }

    /**
     * @throws Exception
     */
    public function testDateTimeValuesAreWrittenThroughColumnTypesAndCompareStably(): void
    {
        date_default_timezone_set('Europe/Amsterdam');
        $connection = $this->requireConnection();
        $connection->executeStatement(sprintf(
            'CREATE TABLE sf_events (external_id VARCHAR(64) PRIMARY KEY, happened_at %s NULL, day DATE NULL)',
            $this->dateTimeColumnSql(),
        ));
        $sync = $this->buildSync(new EntityMetadata(
            ExternalEventStub::class,
            'sf_events',
            ['external_id', 'happened_at', 'day'],
            ['external_id' => 'external_id', 'happened_at' => 'happened_at', 'day' => 'day'],
            ['external_id'],
            ['happened_at', 'day'],
            ['happened_at' => $this->dateTimeDbalType(), 'day' => 'date_immutable'],
        ));
        $amsterdam = new DateTimeZone('Europe/Amsterdam');
        $run = fn (string $local) => $sync->for(ExternalEventStub::class)->key(['external_id'])
            ->source([[
                'external_id' => 'a',
                'happened_at' => new DateTimeImmutable($local, $amsterdam),
                'day' => new DateTimeImmutable($local, $amsterdam),
            ]])->run();

        self::assertSame(1, $run('2024-06-01 00:30:00')->inserted);
        self::assertSame('2024-06-01', substr((string) $connection->fetchOne('SELECT day FROM sf_events'), 0, 10));
        self::assertSame(1, $run('2024-06-01 00:30:00')->unchanged);
        self::assertSame(1, $run('2024-06-01 13:00:00')->updated);
        self::assertSame(1, $run('2024-06-01 13:00:00')->unchanged);
    }

    /**
     * @throws Exception
     */
    private function dropTables(): void
    {
        foreach (['sf_prices', 'sf_items', 'sf_parts', 'sf_events'] as $table) {
            $this->requireConnection()->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }

    private function requireConnection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    private function buildSync(EntityMetadata $metadata): SyncForge
    {
        $connection = $this->requireConnection();
        $keyResolver = new CompositeKeyResolver();

        return new SyncForge(
            metadataProvider: new InMemoryEntityMetadataProvider([$metadata->entityClass => $metadata]),
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
     * @return array{host:string,port:int,dbname:string,user:string,password:string}
     */
    protected function parseDsn(string $envName, int $defaultPort): array
    {
        $dsn = getenv($envName);
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(sprintf('Set %s to run these integration tests.', $envName));
        }

        $parts = parse_url($dsn);
        if ($parts === false) {
            self::fail(sprintf('Invalid %s format.', $envName));
        }

        return [
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? $defaultPort,
            'dbname' => isset($parts['path']) ? ltrim($parts['path'], '/') : 'sync_forge_test',
            'user' => $parts['user'] ?? 'sync_forge',
            'password' => $parts['pass'] ?? 'sync_forge',
        ];
    }

    /**
     * @param 'pdo_pgsql'|'pdo_mysql' $driver
     */
    protected function connect(string $driver, string $envName, int $defaultPort): Connection
    {
        $params = $this->parseDsn($envName, $defaultPort);

        return DriverManager::getConnection([
            'driver' => $driver,
            'host' => $params['host'],
            'port' => $params['port'],
            'dbname' => $params['dbname'],
            'user' => $params['user'],
            'password' => $params['password'],
        ]);
    }
}

final class ExternalPriceStub
{
}

final class ExternalItemStub
{
}

final class ExternalPartStub
{
}

final class ExternalEventStub
{
}
