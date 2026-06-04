# SyncForge

[![CI](https://github.com/fedorlabs/sync-forge/actions/workflows/ci.yml/badge.svg)](https://github.com/fedorlabs/sync-forge/actions/workflows/ci.yml)

SyncForge is a Symfony/Doctrine library for reconciling external datasets (`array`/`iterable`) with an entity table using batched DBAL operations.

> **Status: beta** (`v0.1.0-beta.2`). API is stable enough for integration testing.

Typical use case: you get data from an API, CSV, ERP or marketplace feed, and need to keep a DB table in sync - upsert changed rows, delete rows that disappeared from the source, skip what hasn't changed - all without loading 100k entities into Doctrine's UnitOfWork.

## Features

- Fluent API: `->for(Entity::class)->key([...])->source($rows)->run()`
- Composite key support
- Diff detection - only changed fields go into UPDATE
- Batch execution via DBAL
- Platform executors: PostgreSQL, MySQL/MariaDB, fallback
- `deleteMissing` with streamed key scan
- Dry-run mode
- `continueOnError` with typed error entries in `SyncResult`

## Requirements

- PHP `^8.2`
- Doctrine DBAL `^4.2`
- Symfony + Doctrine ORM (for bundle/autowiring)

## Installation

```bash
composer require sync-forge/sync-forge
```

## Quick Start

```php
$result = $syncForge->for(Product::class)
    ->key(['external_id'])
    ->source($rows)
    ->chunkSize(1000)
    ->deleteMissing(true)
    ->run();

echo $result->inserted;   // new rows
echo $result->updated;    // changed rows
echo $result->deleted;    // rows not in source anymore
echo $result->unchanged;  // skipped (no diff)
```

## Symfony Integration

Register the bundle in `config/bundles.php`:

```php
SyncForge\SyncForgeBundle::class => ['all' => true],
```

The bundle autowires `SyncForge` using `doctrine.orm.entity_manager` and `doctrine.dbal.default_connection`.

```php
final class ProductSyncService
{
    public function __construct(private readonly SyncForge $syncForge) {}

    public function sync(iterable $rows): void
    {
        $this->syncForge->for(Product::class)
            ->key(['externalId'])
            ->source($rows)
            ->chunkSize(1000)
            ->deleteMissing(false)
            ->run();
    }
}
```

## Examples

### Paginated API source

Passing a generator lets SyncForge stream pages without loading everything into memory first:

```php
function fetchFromApi(HttpClientInterface $client): iterable
{
    $page = 1;
    do {
        $data = $client->request('GET', '/api/products', [
            'query' => ['page' => $page, 'per_page' => 500],
        ])->toArray();

        foreach ($data['items'] as $item) {
            yield [
                'external_id' => $item['id'],
                'name'        => $item['title'],
                'price'       => (int) round($item['price'] * 100),
            ];
        }

        $page++;
    } while ($data['has_more'] ?? false);
}

$result = $syncForge->for(Product::class)
    ->key(['external_id'])
    ->source(fetchFromApi($client))
    ->chunkSize(500)
    ->deleteMissing(true)
    ->run();
```

### Composite key

```php
$result = $syncForge->for(StockLevel::class)
    ->key(['sku', 'warehouse_code'])
    ->source($rows)
    ->chunkSize(2000)
    ->run();
```

### Dry-run before delete

```php
$result = $syncForge->for(Product::class)
    ->key(['external_id'])
    ->source($rows)
    ->deleteMissing(true)
    ->dryRun(true)
    ->run();

printf("would delete %d rows\n", $result->deleted);
```

### Error handling

```php
$result = $syncForge->for(Product::class)
    ->key(['external_id'])
    ->source($rows)
    ->continueOnError(true)
    ->run();

if (!$result->isSuccess()) {
    foreach ($result->errors as $error) {
        // $error->type: 'validation' | 'db' | 'pipeline'
        // $error->chunkIndex: which chunk failed (null for delete-missing phase)
        logger->error($error->type . ': ' . $error->message);
    }
}
```

## Sync Result

`run()` returns `SyncResult`:

| Property | Type | Description |
|---|---|---|
| `inserted` | `int` | rows inserted |
| `updated` | `int` | rows updated |
| `deleted` | `int` | rows deleted |
| `unchanged` | `int` | rows with no diff |
| `processedRows` | `int` | total incoming rows |
| `chunkCount` | `int` | number of chunks processed |
| `dryRun` | `bool` | whether writes were skipped |
| `warnings` | `list<string>` | e.g. duplicate key warnings |
| `errors` | `list<ErrorEntry>` | typed errors when `continueOnError` is on |
| `isSuccess()` | `bool` | true when `errors` is empty |

## Limitations

- Bypasses Doctrine lifecycle callbacks (uses DBAL directly).
- Diff is scalar-oriented - no deep JSON comparison.
- `deleteMissing(true)` with an empty source throws as a safety guard.
- No async workers or audit log yet.

## Performance Benchmark

Local numbers on a MacBook (`seed=42`, `chunk=1000`, Docker):

```
== postgres ==
n=1000    time=180ms   mem_delta=2MB   ins=200   upd=200   del=200
n=5000    time=763ms   mem_delta=4MB   ins=1000  upd=1000  del=1000
n=10000   time=1442ms  mem_delta=2MB   ins=2000  upd=2000  del=1000

== mariadb ==
n=1000    time=162ms   mem_delta=0     ins=200   upd=200   del=200
n=5000    time=999ms   mem_delta=0     ins=1000  upd=1000  del=1000
n=10000   time=1219ms  mem_delta=0     ins=2000  upd=2000  del=1000
```

To run yourself:

```bash
composer bench:up
composer bench:run -- --sizes=1000,5000,10000 --chunk=1000 --seed=42
composer bench:down
```

## Integration Tests

- SQLite: no env vars needed, runs with `composer test`
- PostgreSQL: `TEST_PG_DSN=pgsql://sync_forge:sync_forge@127.0.0.1:5432/sync_forge_test`
- MySQL: `TEST_MYSQL_DSN=mysql://sync_forge:sync_forge@127.0.0.1:3306/sync_forge_test`

```bash
composer test-integration-external
```

## Development

```bash
composer lint
composer cs-check
composer cs-fix
composer test-unit
composer test-integration
composer analyse
composer test
```
