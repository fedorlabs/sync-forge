# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Fluent sync API with chunked reconciliation pipeline.
- DBAL bridge components for existing-row loading and platform detection.
- PostgreSQL, MySQL/MariaDB, and fallback batch executors.
- Composite key support and scalar diff engine with partial updates.
- Safety guard for `deleteMissing(true)` with empty input source.
- `continueOnError` mode with aggregated sync errors in `SyncResult`.
- Symfony bundle/extension wiring for autowiring integration.
- Quality tooling:
  - PHPStan (strict/deprecation/phpunit rules)
  - PHP-CS-Fixer
  - Unit and integration tests
  - GitHub Actions CI for SQLite + PostgreSQL + MySQL
  - Dedicated CI quality gate job (`composer cs-check` + `composer analyse`)

### Changed
- `FallbackBatchExecutor` behavior is now explicit dry-run-only in write mode.
- `SyncResult` now exposes `errors` and a non-trivial `isSuccess()`.
- Key encoding is type-aware to avoid collisions between scalar types.
- Date comparison normalization now handles common DB datetime string formats.

### Fixed
- DBAL 4 connection setup in integration tests (`driver` required).
- Identifier quoting in DBAL existing-row lookup paths.
- Deprecated/unsafe SQL identifier quoting patterns in executor paths.

## [0.1.0-beta.1] - 2026-02-26

### Added
- Contribution guide (`CONTRIBUTING.md`).
- Pull request template with quality gate checklist.

### Changed
- README status and tracking links aligned with beta-candidate workflow.

## [0.1.0-alpha] - 2026-02-26

### Added
- Initial public alpha foundation for SyncForge.
