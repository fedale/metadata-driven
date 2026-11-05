# The Symfony CLI exposes DATABASE_URL from the running Docker Compose services.
SYMFONY := symfony
CONSOLE := $(SYMFONY) console
PHP     := $(SYMFONY) php

.DEFAULT_GOAL := help
.PHONY: help up down db db-test assets cs cs-fix phpstan deptrac test qa

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-10s %s\n", $$1, $$2}'

up: ## Start the database container
	docker compose up -d --wait

down: ## Stop the containers
	docker compose down

# The core schema must exist before the first migration: it holds core.doctrine_migration_versions.
db: ## Create the dev database and run migrations
	$(CONSOLE) doctrine:database:create --if-not-exists
	$(CONSOLE) dbal:run-sql --quiet 'CREATE SCHEMA IF NOT EXISTS core'
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

db-test: ## Create the test database and run migrations
	$(CONSOLE) --env=test doctrine:database:create --if-not-exists
	$(CONSOLE) --env=test dbal:run-sql --quiet 'CREATE SCHEMA IF NOT EXISTS core'
	$(CONSOLE) --env=test doctrine:migrations:migrate --no-interaction --allow-no-migration

cs: ## Check coding standard
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix coding standard
	$(PHP) vendor/bin/php-cs-fixer fix

phpstan: ## Static analysis
	$(CONSOLE) cache:warmup --env=dev --quiet
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

deptrac: ## Check layer and module boundaries
	$(PHP) vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml --no-progress
	$(PHP) vendor/bin/deptrac analyse --config-file=deptrac.modules.yaml --no-progress
	$(PHP) vendor/bin/deptrac analyse --config-file=deptrac.template-format.yaml --no-progress

assets: ## Compile the Sass stylesheets (the pages fail to render without them)
	$(CONSOLE) sass:build --quiet

test: up assets ## Run the test suite (starts the database and builds the styles if needed)
	$(PHP) bin/phpunit

qa: cs phpstan deptrac test ## Run every quality check
