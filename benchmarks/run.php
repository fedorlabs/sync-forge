<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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

require __DIR__ . '/../vendor/autoload.php';

final class BenchProduct
{
}

$opts = getopt('', ['sizes::', 'chunk::', 'seed::']);
$sizes = isset($opts['sizes']) ? array_map('intval', explode(',', (string) $opts['sizes'])) : [1000, 5000, 10000];
$chunkSize = isset($opts['chunk']) ? max(1, (int) $opts['chunk']) : 1000;
$seed = isset($opts['seed']) ? (int) $opts['seed'] : 42;

$targets = [
    'postgres' => [
        'driver' => 'pdo_pgsql',
        'host' => '127.0.0.1',
        'port' => 54329,
        'dbname' => 'sync_forge_bench',
        'user' => 'sync_forge',
        'password' => 'sync_forge',
    ],
    'mariadb' => [
        'driver' => 'pdo_mysql',
        'host' => '127.0.0.1',
        'port' => 33069,
        'dbname' => 'sync_forge_bench',
        'user' => 'sync_forge',
        'password' => 'sync_forge',
    ],
];

printf("SyncForge benchmark\n");
printf("sizes=%s chunk=%d seed=%d\n\n", implode(',', $sizes), $chunkSize, $seed);

foreach ($targets as $name => $params) {
    echo "== {$name} ==\n";

    $connection = connectWithRetry($params, 30, 500000);
    recreateSchema($connection, $name);

    foreach ($sizes as $size) {
        mt_srand($seed + $size);

        $existing = generateExistingRows($size);
        seedRows($connection, $name, $existing);

        $incoming = generateIncomingRows($existing, $size);

        $beforeMem = memory_get_usage(true);
        $beforePeak = memory_get_peak_usage(true);
        $t0 = microtime(true);

        $sync = buildSyncForge($connection);
        $result = $sync->for(BenchProduct::class)
            ->key(['external_id'])
            ->source($incoming)
            ->chunkSize($chunkSize)
            ->deleteMissing(true)
            ->run();

        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
        $afterMem = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        printf(
            "n=%-6d  time=%-6dms  mem_delta=%-8s  peak=%-8s  ins=%-6d upd=%-6d del=%-6d\n",
            $size,
            $elapsedMs,
            humanBytes(max(0, $afterMem - $beforeMem)),
            humanBytes(max($beforePeak, $peak)),
            $result->inserted,
            $result->updated,
            $result->deleted,
        );
    }

    echo "\n";
}

function connectWithRetry(array $params, int $attempts, int $sleepMicros): Connection
{
    $last = null;

    for ($i = 0; $i < $attempts; $i++) {
        try {
            $connection = DriverManager::getConnection($params);
            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (Throwable $e) {
            $last = $e;
            usleep($sleepMicros);
        }
    }

    throw new RuntimeException('Failed to connect to database: ' . ($last?->getMessage() ?? 'unknown error'));
}

function recreateSchema(Connection $connection, string $db): void
{
    $connection->executeStatement('DROP TABLE IF EXISTS products_bench');

    if ($db === 'postgres') {
        $connection->executeStatement('CREATE TABLE products_bench (external_id VARCHAR(64) PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL, updated_at TIMESTAMP NOT NULL)');
        return;
    }

    $connection->executeStatement('CREATE TABLE products_bench (external_id VARCHAR(64) PRIMARY KEY, name VARCHAR(255) NOT NULL, price INT NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB');
}

function buildSyncForge(Connection $connection): SyncForge
{
    $metadata = new EntityMetadata(
        entityClass: BenchProduct::class,
        tableName: 'products_bench',
        fields: ['external_id', 'name', 'price', 'updated_at'],
        fieldToColumn: [
            'external_id' => 'external_id',
            'name' => 'name',
            'price' => 'price',
            'updated_at' => 'updated_at',
        ],
        identifierFields: ['external_id'],
        updatableFields: ['name', 'price', 'updated_at'],
    );

    $metadataProvider = new InMemoryEntityMetadataProvider([
        BenchProduct::class => $metadata,
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
 * @return list<array<string,mixed>>
 */
function generateExistingRows(int $size): array
{
    $rows = [];
    $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

    for ($i = 1; $i <= $size; $i++) {
        $rows[] = [
            'external_id' => 'SKU-' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'name' => 'Product ' . $i,
            'price' => 100 + ($i % 100),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string,mixed>> $existing
 * @return list<array<string,mixed>>
 */
function generateIncomingRows(array $existing, int $size): array
{
    $rows = [];
    $keep = (int) floor($size * 0.8);
    $update = (int) floor($size * 0.2);

    $clock = new DateTimeImmutable('2026-01-02 00:00:00', new DateTimeZone('UTC'));

    for ($i = 0; $i < $keep; $i++) {
        $row = $existing[$i];

        if ($i < $update) {
            $row['price'] = (int) $row['price'] + 5;
            $row['updated_at'] = $clock->format('Y-m-d H:i:s');
        }

        $rows[] = $row;
    }

    // Insert 20% new rows.
    for ($i = $size + 1; $i <= $size + ($size - $keep); $i++) {
        $rows[] = [
            'external_id' => 'SKU-' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'name' => 'Product ' . $i,
            'price' => 150 + ($i % 80),
            'updated_at' => $clock->format('Y-m-d H:i:s'),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string,mixed>> $rows
 */
function seedRows(Connection $connection, string $db, array $rows): void
{
    $connection->executeStatement('DELETE FROM products_bench');

    foreach ($rows as $row) {
        $connection->insert('products_bench', $row);
    }
}

function humanBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . 'B';
    }

    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    $unit = 0;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return sprintf('%.1f%s', $value, $units[$unit]);
}
