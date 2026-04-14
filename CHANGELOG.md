# Changelog

## [Unreleased]

## [0.1.0] - TBD

### Added
- DateTime edge case tests: cross-timezone normalization, microseconds, null handling
- Idempotency tests: repeated sync with same source produces zero changes

### Changed
- `SyncResult::$errors` is now `list<ErrorEntry>` instead of `list<string>` — each error carries `type`, `message`, and `chunkIndex`
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
