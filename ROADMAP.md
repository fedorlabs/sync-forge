# Roadmap

## v0.1.0-beta.1 Goals

- Stabilize current API surface for early adopters.
- Keep CI green across:
  - Unit tests
  - SQLite integration
  - PostgreSQL integration
  - MySQL integration
- Keep `composer analyse` and `composer cs-check` green.
- Publish clear release notes and migration expectations.

## v0.1.x (Beta Series)

### Quality and Reliability
- Add benchmark docs (10k / 50k / 100k rows) with memory and runtime profile.
- Improve error classification in `SyncResult` (`validation`, `db`, `pipeline`).
- Add test coverage for:
  - composite-key delete-missing
  - retry-safe idempotency scenarios
  - datetime edge cases across timezone input formats

### SQL/Execution
- Improve `fetchByIncomingRows` strategy for large chunks (`OR` optimization path).
- Add streaming/paginated key loading strategy for large `deleteMissing` scopes.
- Add chunk-level transaction strategy options and docs.

### Developer Experience
- Improve README examples with realistic source adapters.
- Add small Symfony demo app snippet in docs.
- Add release checklist automation in CI.

## v0.2.0 Candidates

- JSON-aware diff strategy (pluggable).
- Explicit sync scope filters for safer delete-missing workflows.
- Optional audit sink interface.
- Async worker orchestration hooks.

