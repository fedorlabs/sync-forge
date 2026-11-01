# Changelog

## [Unreleased]

## [0.2.1] - 2026-11-01

### Added
- `EntityMetadata::$fieldTypes` (DBAL type names by field), filled by the Doctrine ORM provider and accepted as an optional `fieldTypes` key by `CallableEntityMetadataProvider`.
- Warn about keys repeated across chunks (the last row still wins).

### Changed
- Naive database date-time strings are now read in the PHP default timezone when diffing (previously as UTC). If the PHP timezone is not UTC and such values were written by other means as UTC, they are treated as changed and rewritten from the source on the first sync.

### Fixed
- Read the key scan for `deleteMissing` in pages (keyset pagination) instead of one buffered result, so memory no longer grows with table size on PostgreSQL and MySQL.
- Write `DateTimeInterface` source values through the DBAL type of the column, as Doctrine ORM does, instead of failing on insert and update. No timezone shift is applied; `date` columns keep the day and `datetimetz` columns keep the instant.
- Read naive database date-times in the PHP default timezone when diffing, so values written by the ORM and by SyncForge compare equal.
- Collect non-`Exception` errors in `SyncResult` when `continueOnError` is enabled; `Error` throwables are reported with the separate `internal` error type.
- Fix a PHPStan error in the Doctrine ORM metadata provider.

## [0.2.0] - 2026-11-01

### Changed
- Integer keys and their exact string representations now compare equally.
- Skip `deleteMissing` after chunk errors and return a warning.

### Fixed
- Avoid skipped rows when deleting missing records across key batches.
- Reject database matches whose keys cannot be matched to source keys.
- Preserve values and database defaults when inserted rows have different fields.

## [0.1.2] - 2026-11-01

### Changed
- Publish the Composer package as `fedorlabs/sync-forge`.

## [0.1.1] - 2026-11-01

### Added
- Symfony 8.1 compatibility and CI coverage for PHP 8.4 and 8.5.

### Changed
- Upgrade PHPStan and its rule extensions to version 2.

### Fixed
- Restore PHP 8.2 compatibility by removing typed class constants from `ErrorEntry`.

## [0.1.0] - 2026-04-14

### Added
- DateTime edge case tests: cross-timezone normalization, microseconds, null handling
- Idempotency tests: repeated sync with same source produces zero changes

### Changed
- `SyncResult::$errors` is now `list<ErrorEntry>` instead of `list<string>` - each error carries `type`, `message`, and `chunkIndex`
- README rewritten: shorter, paginated source example, benchmark numbers, `SyncResult` table

## [0.1.0-beta.2] - 2026-03-11

### Added
- Integration test for composite key + `deleteMissing`

### Changed
- `DoctrineEntityMetadataProvider` renamed to `CallableEntityMetadataProvider` - the old name implied ORM, but it's a generic callable adapter

### Fixed
- CI badge URL in README

## [0.1.0-beta.1] - 2026-02-09

First public beta.

### Added
- Fluent sync API: `->for(Entity::class)->key([...])->source($rows)->run()`
- Composite key support - single and multi-field
- Scalar diff - only changed fields go into UPDATE
- PostgreSQL executor (`INSERT ... ON CONFLICT`)
- MySQL/MariaDB executor (`INSERT ... ON DUPLICATE KEY UPDATE`)
- Fallback DBAL executor for other platforms
- `deleteMissing(true)` with streamed key scan to avoid loading full keyspace into memory
- Safety guard: `deleteMissing` with empty source throws instead of wiping the table
- Dry-run mode - returns realistic counters without writing
- `continueOnError` - collects chunk errors into `SyncResult` instead of throwing
- Symfony bundle + DI autowiring
- Doctrine ORM metadata bridge
- Unit and integration tests (SQLite, PostgreSQL, MySQL)
- GitHub Actions CI across PHP 8.2 and 8.3

### Fixed
- Identifier quoting in DBAL lookup and executor paths
- `driver` key required in DBAL 4 connection config for integration tests
