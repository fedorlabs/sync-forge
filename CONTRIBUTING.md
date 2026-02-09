# Contributing

Before opening a PR:

```bash
composer cs-check
composer analyse
composer test
```

Use `feature/<topic>` or `fix/<topic>` branches off `main`.

For MySQL/PostgreSQL integration tests set `TEST_MYSQL_DSN` / `TEST_PG_DSN`.
