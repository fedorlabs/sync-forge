# Contributing

Thanks for contributing to SyncForge.

## Branching

- Keep `main` clean and releasable.
- Create feature branches from `main`.
- Recommended naming:
  - `feature/<short-topic>`
  - `fix/<short-topic>`
  - `beta/<version-or-scope>`

## Pull Requests

Before opening a PR, run:

```bash
composer cs-check
composer analyse
composer test
```

PRs should include:
- clear problem statement
- concise change summary
- verification commands/results
- migration notes (if behavior changed)

## Commit Style

Use clear conventional-style subjects when possible:

- `feat: ...`
- `fix: ...`
- `docs: ...`
- `chore: ...`

Keep commits scoped and reviewable.

## Testing

Default local suite:

```bash
composer test
```

External DB integration suites:

```bash
composer test-integration-external
```

Use env vars:
- `TEST_PG_DSN`
- `TEST_MYSQL_DSN`

## Release Flow

Use:
- `RELEASE_CHECKLIST.md`
- `.github/RELEASE_TEMPLATE.md`
- `CHANGELOG.md`

for beta/stable release preparation.
