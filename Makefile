SHELL := /bin/sh

.PHONY: test test-unit test-integration test-integration-external lint analyse cs-check cs-fix

test:
	@vendor/bin/phpunit --testsuite Unit
	@vendor/bin/phpunit --filter SyncPipelineSqliteTest

test-unit:
	@vendor/bin/phpunit --testsuite Unit

test-integration:
	@vendor/bin/phpunit --filter SyncPipelineSqliteTest

test-integration-external:
	@vendor/bin/phpunit --filter "SyncPipeline(Postgres|MySql)Test"

lint:
	@find src tests config -name '*.php' -print0 | xargs -0 -n1 php -l

analyse:
	@vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=1G

cs-check:
	@vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes --dry-run --diff

cs-fix:
	@vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes
