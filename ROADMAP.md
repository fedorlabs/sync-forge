# Roadmap

## v0.1.0 - done

- Fluent sync API with chunked reconciliation pipeline
- Composite key support, scalar diff, partial updates
- PostgreSQL and MySQL/MariaDB executors
- Streamed key scan for `deleteMissing`
- Dry-run mode, `continueOnError`
- Typed error entries in `SyncResult` (`validation` / `db` / `pipeline`)
- Symfony bundle + DI autowiring
- Doctrine ORM metadata bridge
- Unit and integration tests (SQLite, PostgreSQL, MySQL)
- Idempotency and datetime edge case coverage
- Benchmark docs

## v0.1.x - maintenance

- Datetime edge cases across additional DB drivers if reported
- `fetchByIncomingRows` OR optimization for very large single-key chunks
- Chunk-level transaction strategy docs

## v0.2.0

- JSON-aware diff strategy (pluggable)
- Explicit scope filter for `deleteMissing` (bound sync to a subset of rows)
- Optional audit sink interface
- Async worker orchestration hooks
