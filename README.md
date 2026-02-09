# SyncForge

[![CI](https://github.com/flarvin/sync-forge/actions/workflows/ci.yml/badge.svg)](https://github.com/flarvin/sync-forge/actions/workflows/ci.yml)

SyncForge is a Symfony/Doctrine library for reconciling external datasets (`array`/`iterable`) with an entity table using batched DBAL operations.

> Status: **beta candidate** (`v0.1.0-beta.1` track).
>
> Tracking docs:
> - [Changelog](./CHANGELOG.md)
> - [Roadmap](./ROADMAP.md)
> - [Contributing](./CONTRIBUTING.md)
> - [Release Checklist](./RELEASE_CHECKLIST.md)
> - [Release Template](./.github/RELEASE_TEMPLATE.md)

Typical use case:
- you receive data from API/CSV/ERP/marketplace
- you need to find existing records
- detect changes
- perform upsert
- delete missing rows
- do it in chunks without loading 100k entities into Doctrine UnitOfWork

## Features

- Fluent API:
  - `for(Entity::class)`
  - `key([...])`
  - `source(iterable)`
  - `chunkSize(int)`
  - `deleteMissing(bool)`
  - `dryRun(bool)`
  - `continueOnError(bool)`
  - `run()`
- Composite key support.
- Diff detection with partial updates (changed fields only).
- Batch execution via DBAL.
- Platform executors:
  - PostgreSQL
  - MySQL/MariaDB
  - fallback executor
- Dry-run mode (plan without writes).

## Requirements

- PHP `^8.2`
- Doctrine DBAL `^4.2`
- Symfony + Doctrine ORM (for bundle/autowiring integration)

## Installation

```bash
composer require sync-forge/sync-forge
```

For local development of this repository:

```bash
composer install
```

## Quick Start

```php
<?php

use SyncForge\SyncForge;

$result = $syncForge->for(Product::class)
    ->key(['external_id'])
    ->source($rows)
    ->chunkSize(1000)
    ->deleteMissing(true)
    ->dryRun(false)
    ->run();

// $result->inserted / updated / deleted / unchanged
```

## Symfony Integration

1. Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    SyncForge\SyncForgeBundle::class => ['all' => true],
];
```

2. Ensure these Doctrine services exist:
- `doctrine.orm.entity_manager`
- `doctrine.dbal.default_connection`

3. Use `SyncForge\SyncForge` through DI/autowiring:

```php
<?php

namespace App\Service;

use SyncForge\SyncForge;

final class ProductSyncService
{
    public function __construct(private readonly SyncForge $syncForge)
    {
    }

    public function sync(iterable $rows): void
    {
        $this->syncForge->for(\App\Entity\Product::class)
            ->key(['externalId'])
            ->source($rows)
            ->chunkSize(1000)
            ->deleteMissing(false)
            ->run();
    }
}
```

## Examples

### 1. Upsert only (no delete)

```php
$result = $syncForge->for(Product::class)
    ->key(['external_id'])
    ->source($supplierRows)
    ->run();
```

### 2. Composite key

```php
$result = $syncForge->for(StockLevel::class)
    ->key(['sku', 'warehouse_code'])
    ->source($rows)
    ->chunkSize(2000)
    ->run();
```

### 3. Dry-run

```php
$result = $syncForge->for(Product::class)
    ->key(['external_id'])
    ->source($rows)
    ->deleteMissing(true)
    ->dryRun(true)
    ->run();

if ($result->dryRun) {
    // Inspect counters and plan impact before real write mode
}
```

## Current MVP Limitations

- Bulk path uses DBAL and bypasses Doctrine lifecycle callbacks.
- Diff is scalar-oriented (no deep JSON semantics).
- `deleteMissing(true)` should be enabled only with a clearly bounded sync scope.
- `deleteMissing(true)` with an empty source is blocked by default as a safety guard.
- Core fallback executor is dry-run only. Real writes require a DBAL-backed executor setup.
- No async workers, audit storage, or monitoring UI yet.

## Sync Result

`run()` returns `SyncResult` with:
- `processedRows`
- `inserted`
- `updated`
- `deleted`
- `unchanged`
- `chunkCount`
- `dryRun`
- `warnings`
- `errors`

## Development Commands

```bash
composer lint
composer cs-check
composer cs-fix
composer test-unit
composer test-integration
composer test-integration-external
composer analyse
composer test
```

or

```bash
make lint
make cs-check
make cs-fix
make test-unit
make test-integration
make test-integration-external
make analyse
make test
```

## Local Performance Benchmark

You can run a local benchmark against PostgreSQL and MariaDB using Docker:

```bash
composer bench:up
composer bench:run -- --sizes=1000,5000,10000 --chunk=1000 --seed=42
composer bench:down
```

This benchmark reports:
- sync runtime (ms)
- memory delta
- peak memory
- insert/update/delete counters

Example output on a local machine (`seed=42`, `chunk=1000`):

```text
== postgres ==
n=1000    time=122ms   ins=200  upd=200  del=200
n=5000    time=999ms   ins=1000 upd=1000 del=1000
n=10000   time=2529ms  ins=2000 upd=2000 del=2000

== mariadb ==
n=1000    time=123ms   ins=200  upd=200  del=200
n=5000    time=1302ms  ins=1000 upd=1000 del=1000
n=10000   time=4086ms  ins=2000 upd=2000 del=2000
```

Numbers depend on CPU, disk, Docker runtime, and host load.

## Integration Tests

- SQLite: runs with no additional env vars.
- PostgreSQL: use `composer test-integration-external` with `TEST_PG_DSN`.
- MySQL: use `composer test-integration-external` with `TEST_MYSQL_DSN`.

Example DSNs:
- `TEST_PG_DSN=pgsql://sync_forge:sync_forge@127.0.0.1:5432/sync_forge_test`
- `TEST_MYSQL_DSN=mysql://sync_forge:sync_forge@127.0.0.1:3306/sync_forge_test`

If DSN env vars are missing, external DB integration tests are skipped.

## CI

GitHub Actions workflow: `.github/workflows/ci.yml`

Pipeline runs:
- lint + unit + SQLite integration
- PostgreSQL integration
- MySQL integration

## Release Readiness

Before cutting `v0.1.0-beta.1`:
1. CI is green on the release branch.
2. `composer cs-check`, `composer analyse`, and `composer test` are green.
3. PostgreSQL and MySQL integration jobs pass in CI.
4. `CHANGELOG.md` and release notes are updated.
