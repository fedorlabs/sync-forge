# SyncForge

[![CI](https://github.com/fedorlabs/sync-forge/actions/workflows/ci.yml/badge.svg)](https://github.com/fedorlabs/sync-forge/actions/workflows/ci.yml)

SyncForge is a Symfony/Doctrine library for reconciling external datasets (`array`/`iterable`) with an entity table using batched DBAL operations.

> **Status:** Early stable release (`v0.3.1`).

Typical use case: you get data from an API, CSV, ERP or marketplace feed, and need to keep a DB table in sync - upsert changed rows, delete rows that disappeared from the source, skip what hasn't changed - all without loading 100k entities into Doctrine's UnitOfWork.

With the Doctrine ORM metadata provider, keys and source row keys are mapped
entity field names, not database column names. For example, use `externalId`
for an entity property mapped to `external_id`. Every row must contain every
configured key field. Ensure those fields uniquely identify a row in the table;
PostgreSQL `ON CONFLICT` also requires a matching unique constraint or index.

## Features

- Fluent API: `->for(Entity::class)->key([...])->source($rows)->run()`
- Composite key support
- Diff detection - only changed fields go into UPDATE
- Batch execution via DBAL
- Platform executors: PostgreSQL, MySQL/MariaDB, fallback
- `deleteMissing` with batched key processing
- Dry-run mode
- `continueOnError` with typed error entries in `SyncResult`
- `scope()` to limit lookups and deletion to a subset of the table
- `returnInserted()` to get the keys and generated ids of inserted rows

## Requirements

- PHP `^8.2`
- Doctrine DBAL `^4.2`
- Symfony 6.4, 7.x or 8.1+ (Symfony 8 requires PHP 8.4+)
- Doctrine ORM (for bundle/autowiring)

## Installation

```bash
composer require fedorlabs/sync-forge
```

## Quick Start

```php
$result = $syncForge->for(Product::class)
    ->key(['externalId'])
    ->source($rows)
    ->chunkSize(1000)
    ->deleteMissing(false)
    ->run();

echo $result->inserted;   // new rows
echo $result->updated;    // changed rows
echo $result->deleted;    // deleted rows (zero when deleteMissing is disabled)
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
                'externalId'  => $item['id'],
                'name'        => $item['title'],
                'price'       => (int) round($item['price'] * 100),
            ];
        }

        $page++;
    } while ($data['has_more'] ?? false);
}

$result = $syncForge->for(Product::class)
    ->key(['externalId'])
    ->source(fetchFromApi($client))
    ->chunkSize(500)
    ->deleteMissing(false)
    ->run();
```

### Composite key

```php
$result = $syncForge->for(StockLevel::class)
    ->key(['sku', 'warehouseCode'])
    ->source($rows)
    ->chunkSize(2000)
    ->run();
```

### Scope: replace one supplier's data

`scope()` limits the sync to rows matching the given field values. Existing rows are looked
up and deleted only inside the scope, and the scope values are filled into inserted rows that
do not carry them. A source row with a different value for a scope field is rejected.

```php
$result = $syncForge->for(Price::class)
    ->key(['supplierId', 'sku'])
    ->scope(['supplierId' => 42])
    ->source($rows)
    ->deleteMissing(true)
    ->run();
```

Scope fields must be mapped and must be part of the sync key, values must be scalar or `null`,
and the existing rows provider
must implement `ScopedExistingRowsProviderInterface` (the DBAL one does); otherwise the run
fails instead of reading or deleting rows outside the scope.

The scope fields have to be in the key because rows are looked up inside the scope only: with a
key such as `sku` alone, a row of another scope with the same `sku` is not found, planned as an
insert, and the upsert would then take that row over. The run is rejected before anything is written.

### Chaining child records

`returnInserted()` fills `SyncResult::$insertedKeys` with the key fields and identifier fields
of every inserted row, read back from the database after each chunk, so generated ids can be
used to insert child rows. In a dry run only the key fields of the planned inserts are returned.
The list is kept in memory for the whole run, so enable it only when the number of inserted
rows is manageable.

```php
$result = $syncForge->for(Part::class)
    ->key(['sku'])
    ->source($rows)
    ->returnInserted()
    ->run();

foreach ($result->insertedKeys as $row) {
    // ['sku' => 'B-2', 'id' => 17]
}
```

### Dry-run before delete

Only enable deletion when the source is a complete snapshot of the entire table.
Start with a dry run and inspect `deleted`, `warnings`, and `errors`. A dry run
does not guarantee that a later run is safe: the source or database may change.

```php
$result = $syncForge->for(Product::class)
    ->key(['externalId'])
    ->source($rows)
    ->deleteMissing(true)
    ->dryRun(true)
    ->run();

printf("would delete %d rows\n", $result->deleted);
```

### Error handling

```php
$result = $syncForge->for(Product::class)
    ->key(['externalId'])
    ->source($rows)
    ->continueOnError(true)
    ->run();

if (!$result->isSuccess()) {
    foreach ($result->errors as $error) {
        // $error->type: 'validation' | 'db' | 'pipeline' | 'internal'
        // $error->chunkIndex: which chunk failed (null for delete-missing phase)
        // Log or otherwise handle $error->type and $error->message.
    }
}
```

## Sync Result

`run()` returns `SyncResult`:

| Property | Type | Description |
|---|---|---|
| `inserted` | `int` | planned inserts executed, or planned inserts in dry-run |
| `updated` | `int` | planned updates executed, or planned updates in dry-run |
| `deleted` | `int` | planned deletes executed, or planned deletes in dry-run |
| `unchanged` | `int` | rows with no diff |
| `processedRows` | `int` | total incoming rows |
| `chunkCount` | `int` | number of chunks processed |
| `dryRun` | `bool` | whether writes were skipped |
| `warnings` | `list<string>` | e.g. duplicate key warnings |
| `errors` | `list<ErrorEntry>` | typed errors when `continueOnError` is on |
| `isSuccess()` | `bool` | true when `errors` is empty |

## Limitations

- Writes use DBAL directly and bypass Doctrine's UnitOfWork, lifecycle callbacks,
  entity listeners, and subscribers. Search indexing, timestamps, audit records,
  and other listener-driven effects must be handled separately when needed.
  Already managed entities may be stale; clear or refresh them before reuse.
- Diff is scalar-oriented. Values the application passes as objects or arrays (for example
  `Money` or a PHP array for a `json` column) are converted with the DBAL type of the column,
  like Doctrine ORM does, when `fieldTypes` names a registered type; `BackedEnum` values are
  written as their backing value. Scalars and `null` are written as they are, so a value that
  is already in database form is not converted twice. `json` columns are compared structurally
  (key order and whitespace are ignored); there is no deep comparison for other types, and
  `convertToDatabaseValueSQL()` of custom types is not applied.
- `DateTimeInterface` values are written the same way Doctrine ORM writes them: through the
  DBAL type of the column (`date`, `datetime`, `datetimetz` and their immutable variants), with
  no shift to another timezone. The Doctrine ORM metadata provider reads the types from the
  mapping; with other metadata providers pass `fieldTypes`, otherwise `datetime_immutable` is used.
  Naive database values are read in the PHP default timezone, as the ORM does.
- Date/time comparison has second precision; differences only in microseconds are not detected.
  `date` columns compare only the day, timezone-aware columns compare the instant, other columns
  compare wall-clock time.
- PostgreSQL and MySQL split inserts into consecutive groups with matching mapped
  columns. Missing fields are omitted so database defaults can apply.
- `deleteMissing(true)` with an empty source throws as a safety guard.
- `deleteMissing(true)` scans the entire mapped table unless `scope()` is set.
- An incomplete non-empty source can delete valid rows. Validate pagination and
  source completeness before enabling deletion; a source that silently ends
  early is indistinguishable from a complete snapshot. An exception while reading
  the source aborts the run, but earlier chunks may already have been written.
- With `continueOnError(true)`, deletion is skipped if a chunk fails, and the
  result contains errors and a warning. Earlier successful batches remain written.
- `continueOnError` catches any `Throwable` during chunk processing and deletion.
  Source iteration errors and upfront validation (key fields, empty source with
  `deleteMissing`) still abort the run without returning a `SyncResult`.
- The DBAL executors use a transaction for each executed plan, including each
  deletion batch, rather than one transaction around the complete sync. A failure
  can leave previous batches committed unless the caller manages an outer transaction.
- The pipeline retains incoming key signatures for the duration of a run, even
  when deletion is disabled. Memory use therefore grows with distinct source keys.
- Key scanning reads the table in pages using keyset pagination on the key columns,
  so a page never holds more than `chunkSize` rows. It requires non-NULL key values
  and an index on the key columns for large tables.
- Integer keys and their exact decimal string representations compare equally;
  leading zeros remain significant. If the database matches a key that the pipeline
  cannot match, the chunk fails rather than treating that row as missing.
- Duplicate keys within a chunk, and keys repeated from earlier chunks, produce a
  warning; the last row wins. Detecting repeats across chunks relies on the
  retained key set, so it adds no extra memory.
- DBAL executor counters are based on planned operations, not database affected-row
  counts. Concurrent writes and database conflict handling can make them differ.
- No async workers or audit log yet.

## Performance Benchmark

The benchmark runs against dedicated PostgreSQL and MariaDB containers and
reports runtime, process memory and operation counters. Results depend on the
environment; measure with a representative dataset before capacity planning.

It drops and recreates `products_bench` in the configured benchmark databases.
The `bench:down` command removes Compose volumes. Use only disposable databases
and verify the Docker Compose project and volume configuration first.

To run it:

```bash
composer bench:up
composer bench:run -- --sizes=1000,5000,10000 --chunk=1000 --seed=42
composer bench:down
```

## Integration Tests

- SQLite: no env vars needed, runs with `composer test`
- PostgreSQL: `TEST_PG_DSN=pgsql://sync_forge:sync_forge@127.0.0.1:5432/sync_forge_test`
- MySQL: `TEST_MYSQL_DSN=mysql://sync_forge:sync_forge@127.0.0.1:3306/sync_forge_test`
- CI runs PostgreSQL integration on PHP 8.3 and 8.5 with PostgreSQL 16.

External database tests drop and recreate a table named `products`. Use dedicated
test databases, never a database containing application data. Missing DSNs cause
the corresponding tests to be skipped.

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
