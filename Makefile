.PHONY: git-hooks-check help install migrate db-reset-full seed-fake pint pint-test test test-changed test-integration test-seed-command test-redis test-docker test-quickbooks-sandbox test-quick test-coverage coverage-html pest-mutate test-ai test-mutate lint lint-sequential lint-full fix analyse phparkitect deptrac tlint tlint-full psalm psalm-ci psalm-baseline stan rector rector-dry-run refactor check ide-helper test-domain test-domain-coverage test-app test-app-coverage mutate-domain mutate-app supabase-start supabase-functions supabase-stop supabase-status supabase-reset supabase-seed-users redis serve db-up stack-down stack-logs pail

# Enable strict shell mode for robust error handling
SHELL := bash
# intentional: macOS make 3.81 ignores .SHELLFLAGS (no -e, no pipefail), so join a step that must not run after a failure with &&, never ;
.SHELLFLAGS := -eu -o pipefail -c
.DELETE_ON_ERROR:
MAKEFLAGS += --warn-undefined-variables

# Color output (conditional on TTY)
ifeq ($(shell test -t 1 && echo true),true)
BLUE := \033[0;34m
GREEN := \033[0;32m
YELLOW := \033[1;33m
NC := \033[0m
else
BLUE :=
GREEN :=
YELLOW :=
NC :=
endif

# Configuration
CI ?= false
# Every recipe that opens the database runs through it: a worktree gets its own Postgres or a refusal (bin/worktree-db).
DB_RUN := bin/worktree-db
IN_WORKTREE = [ "$$($(DB_RUN) --is-worktree)" = 1 ]
# A worktree runs none of the shared Supabase's targets: they act on alz-admin's main stack (COR-375/D10).
REFUSE_IN_WORKTREE = if $(IN_WORKTREE); then echo "make $@ acts on the shared Supabase; run it in the main checkout (this worktree's database: make db-up)" >&2; exit 75; fi
GIT_HOOKS_TEST := tests/Unit/Static/GitHooksInstalledTest.php
# intentional: the empty-suite flag is added only when TEST_ARGS is set (the offline dev image's `--exclude-group=repo-static`), so a missing guard file still fails a plain `make lint`.
GIT_HOOKS_ARGS := $(TEST_ARGS)$(if $(TEST_ARGS), --do-not-fail-on-empty-test-suite)

# Check if inside Docker container
IN_DOCKER := $(shell test -f /.dockerenv && echo yes)

# Check if Docker daemon is available
DOCKER_AVAILABLE := $(shell docker info > /dev/null 2>&1 && echo yes)

# Determine execution mode based on environment
ifeq ($(CI),true)
	# CI environment - use native commands
	EXEC = php
	COMPOSER = composer
	MODE = $(BLUE)[CI Mode]$(NC)
else ifeq ($(IN_DOCKER),yes)
	# Inside Docker container - use native commands
	EXEC = php
	COMPOSER = composer
	MODE = $(GREEN)[Container Mode]$(NC)
else ifneq ($(DOCKER_AVAILABLE),yes)
	# Docker daemon not responding - use native commands
	EXEC = php
	COMPOSER = composer
	MODE = $(YELLOW)[Native Mode - Docker unavailable]$(NC)
else
	# Local host with Docker available - use native commands
	EXEC = php
	COMPOSER = composer
	MODE = $(GREEN)[Native Mode]$(NC)
endif

help: ## Show this help message
	@echo "$(GREEN)Available commands:$(NC)"
	@awk 'BEGIN {FS = ":.*##"; printf "\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  $(BLUE)%-20s$(NC) %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo ""
	@echo "Mode: $(MODE)"

# Installation & Setup
# intentional: plain composer/php, never $(COMPOSER)/$(EXEC), so install stays native whatever the execution mode (COR-269).
# A worktree install stops after the key: its database is its own (make db-up), never the shared Supabase (COR-375/D11).
install: ## Set up a fresh clone or git worktree natively (.env, deps, git hooks, key; main checkout: Supabase, Redis, migrate)
	@if ! $(IN_WORKTREE) && [ -z "$(ALZ_ADMIN)" ]; then \
		echo "ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin" >&2; exit 1; \
	fi
	@bin/install-env
	composer install
	@bin/install-git-hooks
	@if grep -Eq "^APP_KEY=[\"']?[^\"'[:space:]]" .env; then \
		echo "APP_KEY already set; kept"; \
	else \
		php artisan key:generate; \
	fi
	@if $(IN_WORKTREE); then \
		echo "$(GREEN)Setup complete. Next: make db-up (this worktree's Postgres), then make test$(NC)"; \
	else \
		$(MAKE) supabase-start && $(MAKE) redis && php artisan migrate && \
		echo "$(GREEN)Setup complete. Next: make test$(NC)"; \
	fi

# Code Quality Commands
pint: ## Auto-fix code style with Pint
	@echo "$(MODE)"
	$(EXEC) vendor/bin/pint --parallel

pint-test: ## Test code style (dry-run)
	@echo "$(MODE)"
	$(EXEC) vendor/bin/pint --test --parallel

lint: ## Run parallel lint (Pint + PHPStan + PHPArkitect + Deptrac + TLint-fast)
	@echo "$(MODE)"
	@rm -rf /tmp/alz-lint && mkdir -p /tmp/alz-lint
	@$(EXEC) vendor/bin/pint --test --parallel > /tmp/alz-lint/1-pint.txt 2>&1 & P1=$$!; \
	 $(EXEC) -d xdebug.mode=off vendor/bin/phpstan analyse > /tmp/alz-lint/2-phpstan.txt 2>&1 & P2=$$!; \
	 $(EXEC) -d xdebug.mode=off vendor/bin/phparkitect check > /tmp/alz-lint/3-phparkitect.txt 2>&1 & P3=$$!; \
	 $(EXEC) -d xdebug.mode=off vendor/bin/deptrac analyse --fail-on-uncovered --report-uncovered > /tmp/alz-lint/4-deptrac.txt 2>&1 & P4=$$!; \
	 (vendor/bin/tlint lint app/ && vendor/bin/tlint lint routes/) > /tmp/alz-lint/5-tlint.txt 2>&1 & P5=$$!; \
	 $(EXEC) vendor/bin/pest $(GIT_HOOKS_TEST) --no-coverage $(GIT_HOOKS_ARGS) > /tmp/alz-lint/6-git-hooks.txt 2>&1 & P6=$$!; \
	 E1=0; E2=0; E3=0; E4=0; E5=0; E6=0; \
	 wait $$P1 || E1=$$?; wait $$P2 || E2=$$?; wait $$P3 || E3=$$?; wait $$P4 || E4=$$?; wait $$P5 || E5=$$?; wait $$P6 || E6=$$?; \
	 echo "=== Pint ===" && cat /tmp/alz-lint/1-pint.txt; \
	 echo "=== PHPStan ===" && cat /tmp/alz-lint/2-phpstan.txt; \
	 echo "=== PHPArkitect ===" && cat /tmp/alz-lint/3-phparkitect.txt; \
	 echo "=== Deptrac ===" && cat /tmp/alz-lint/4-deptrac.txt; \
	 echo "=== TLint ===" && cat /tmp/alz-lint/5-tlint.txt; \
	 echo "=== Git hooks ===" && cat /tmp/alz-lint/6-git-hooks.txt; \
	 rm -rf /tmp/alz-lint; \
	 [ $$E1 -eq 0 ] && [ $$E2 -eq 0 ] && [ $$E3 -eq 0 ] && [ $$E4 -eq 0 ] && [ $$E5 -eq 0 ] && [ $$E6 -eq 0 ]

git-hooks-check: ## Fail unless the pre-commit and pre-push hooks are registered (fix: make install)
	@echo "$(MODE)"
	$(EXEC) vendor/bin/pest $(GIT_HOOKS_TEST) --no-coverage $(GIT_HOOKS_ARGS)

lint-sequential: ## Run sequential lint (Pint + PHPStan + PHPArkitect + Deptrac + TLint)
	@echo "$(MODE)"
	@$(MAKE) pint-test
	@$(MAKE) analyse
	@$(MAKE) phparkitect
	@$(MAKE) deptrac
	@$(MAKE) tlint
	@$(MAKE) git-hooks-check

lint-full: ## Run full linting (Pint + PHPStan + PHPArkitect + Deptrac + TLint + Psalm)
	@echo "$(MODE)"
	@$(MAKE) pint-test
	@$(MAKE) analyse
	@$(MAKE) phparkitect
	@$(MAKE) deptrac
	@$(MAKE) tlint-full
	@$(MAKE) git-hooks-check
	@$(MAKE) psalm

fix: ## Auto-fix code style with Pint
	@echo "$(MODE)"
	@$(MAKE) pint

analyse: ## Run PHPStan Level max static analysis
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/phpstan analyse

phparkitect: ## Run PHPArkitect architecture checks
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/phparkitect check

deptrac: ## Run Deptrac layer dependency analysis (strict: fails on uncovered)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/deptrac analyse --fail-on-uncovered

tlint: ## Run TLint on app/ + routes/ (fast, ~2s)
	@echo "$(MODE)"
	vendor/bin/tlint lint app/ && vendor/bin/tlint lint routes/

tlint-full: ## Run TLint on entire codebase (~7s)
	@echo "$(MODE)"
	vendor/bin/tlint

psalm: ## Run Psalm taint analysis (local macOS only - use psalm-ci in CI)
	@echo "$(MODE)"
	@# PHP_INI_SCAN_DIR=/dev/null prevents JIT segfaults on ARM64 macOS (PHP 8.4+)
	@# See: https://github.com/vimeo/psalm/issues/11310
	@# WARNING: This breaks CI because shivammathur/setup-php loads extensions via scan dir.
	@# Use 'make psalm-ci' in GitHub Actions instead.
	PHP_INI_SCAN_DIR=/dev/null $(EXEC) -d xdebug.mode=off vendor/bin/psalm --taint-analysis

psalm-ci: ## Run Psalm taint analysis (CI only - extensions loaded via ini scan dir)
	@echo "$(MODE)"
	@# CI version: extensions are pre-loaded by shivammathur/setup-php
	@# Do NOT use PHP_INI_SCAN_DIR=/dev/null here - it breaks extension loading
	$(EXEC) -d xdebug.mode=off vendor/bin/psalm --taint-analysis

psalm-baseline: ## Generate Psalm baseline for existing issues
	@echo "$(MODE)"
	@# Uses same PHP_INI_SCAN_DIR workaround as psalm target (local use only)
	PHP_INI_SCAN_DIR=/dev/null $(EXEC) -d xdebug.mode=off vendor/bin/psalm --taint-analysis --set-baseline=psalm-baseline.xml

stan: ## Alias for analyse (PHPStan)
	@echo "$(MODE)"
	@$(MAKE) analyse

# Refactoring
rector: ## Run Rector refactoring (apply changes)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/rector process

rector-dry-run: ## Preview Rector changes (dry-run)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/rector process --dry-run

refactor: ## Run Rector + Pint combo
	@echo "$(MODE)"
	@$(MAKE) rector
	@$(MAKE) fix

# Testing (layer-based, see tests/TestingStrategy.md)
test: ## Run Pest test suite (all layers, excludes integration, seed-command, list-parity, real-Slack, real-Redis and Docker tests; matches CI)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) vendor/bin/pest --parallel --exclude-group=integration --exclude-group=seed-command --exclude-group=list-parity --exclude-group=slack --exclude-group=redis --exclude-group=docker --no-coverage $(TEST_ARGS)

test-changed: ## Run only the tests changed since the merge-base with origin/HEAD, as the pre-push hook does (CI runs the full suite)
	@echo "$(MODE)"
	@config="$$(mktemp phpunit-changed.XXXXXX)" && trap 'rm -f "$$config"' EXIT && \
		bin/changed-tests --phpunit-config "$$config" && \
		if [ -s "$$config" ]; then \
			$(DB_RUN) $(EXEC) vendor/bin/pest --configuration="$$config" --parallel --processes=4 --no-coverage --do-not-fail-on-empty-test-suite --exclude-group=integration --exclude-group=seed-command --exclude-group=list-parity --exclude-group=slack --exclude-group=redis --exclude-group=docker $(TEST_ARGS); \
		fi

test-redis: ## Run the tests that need a live Redis (start it with make redis)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) vendor/bin/pest --group=redis --no-coverage $(TEST_ARGS)

test-docker: ## Run the tests that need a Docker daemon (verify's bench helpers against throwaway containers)
	@echo "$(MODE)"
	$(EXEC) vendor/bin/pest --group=docker --no-coverage $(TEST_ARGS)

test-integration: ## Run integration tests only (needs local Supabase DB seeded by make db-reset-full; excludes real-Slack and QuickBooks sandbox tests)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) vendor/bin/pest --parallel --group=integration --exclude-group=slack --exclude-group=quickbooks-sandbox

test-quickbooks-sandbox: ## Run the QuickBooks sandbox tests against the realm in .env (connect first with php artisan quickbooks:connect)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) vendor/bin/pest --configuration=phpunit-quickbooks-sandbox.xml --group=quickbooks-sandbox --no-coverage $(TEST_ARGS)

test-seed-command: ## Run the dev:seed-fake command tests (serial; needs local Supabase DB, writes inside a rolled-back transaction)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) vendor/bin/pest --group=seed-command --no-coverage

test-quick: ## Run Domain tests only (fast, no external deps)
	@echo "$(MODE)"
	$(EXEC) vendor/bin/pest --testsuite=Domain --parallel

test-domain: ## Run Domain layer tests (90%+ coverage target)
	@echo "$(MODE)"
	$(EXEC) vendor/bin/pest --testsuite=Domain --parallel

test-domain-coverage: ## Run Domain tests with 90% coverage (Domain code only)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=coverage vendor/bin/pest --configuration=phpunit-domain.xml --coverage --min=90

test-app: ## Run Application layer tests (70%+ coverage target)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) vendor/bin/pest --testsuite=Application --parallel

test-app-coverage: ## Run Application tests with 70% coverage (App code only)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) -d xdebug.mode=coverage vendor/bin/pest --configuration=phpunit-app.xml --coverage --min=70

test-coverage: ## Run Domain (90%) + Application (70%) coverage checks in parallel - PR gate
	@echo "$(YELLOW)Running layer coverage checks in parallel...$(NC)"
	@$(MAKE) -j2 test-domain-coverage test-app-coverage
	@echo "$(GREEN)✓ All layer coverage thresholds passed (Domain 90%, Application 70%)$(NC)"

test-coverage-ci: ## Generate coverage.xml for CI/Codecov upload (all tests)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) -d xdebug.mode=coverage vendor/bin/pest --exclude-group=list-parity --exclude-group=quickbooks-sandbox --exclude-group=docker --coverage-clover=coverage.xml

coverage-html: ## Generate HTML coverage report (open coverage-report/index.html)
	@echo "$(MODE)"
	@mkdir -p coverage-report
	$(DB_RUN) $(EXEC) -d xdebug.mode=coverage vendor/bin/pest --exclude-group=list-parity --exclude-group=quickbooks-sandbox --exclude-group=docker --coverage-html=coverage-report
	@echo "$(GREEN)Coverage report generated: coverage-report/index.html$(NC)"

pest-mutate: ## Run Pest mutation testing
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) -d xdebug.mode=off vendor/bin/pest --mutate --everything --covered-only --min=85 --parallel --processes=9 --exclude-group=quickbooks-sandbox --exclude-group=list-parity --exclude-group=docker

# Layer-specific mutation testing (see tests/TestingStrategy.md)

mutate-domain: ## Run Pest mutation testing on Domain layer (90%+ min score)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/pest --mutate \
		--path=app/Domain \
		--ignore=app/Domain/Exceptions,app/Domain/**/Exceptions \
		--everything --min=90 --parallel --processes=9 \
		--testsuite=Domain --ignore-min-score-on-zero-mutations

mutate-app: ## Run Pest mutation testing on Application layer (70%+ min score)
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) -d xdebug.mode=off vendor/bin/pest --mutate \
		--path=app/Application \
		--covered-only --min=70 --parallel --processes=9 \
		--testsuite=Application --ignore-min-score-on-zero-mutations

test-ai: ## Validate AI-generated tests with mutation testing
	@echo "$(MODE)"
	@$(MAKE) test
	@$(MAKE) pest-mutate

test-mutate: ## Run full mutation testing suite
	@echo "$(MODE)"
	@$(MAKE) test
	@$(MAKE) mutate-domain
	@$(MAKE) mutate-app

# Database
migrate: ## Run database migrations
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) artisan migrate

# =============================================================================
# Database Reset (Coordinated)
# =============================================================================
# NOTE: The supabase-* targets below require a private companion frontend
# project (set via ALZ_ADMIN env var). They are not usable by external
# contributors cloning this repo standalone.
#
# IMPORTANT: This project shares PostgreSQL with Supabase (alz-admin).
# Supabase owns auth.* tables. NEVER run migrate:fresh, migrate:refresh,
# migrate:reset, or db:wipe directly - they destroy Supabase auth tables.
# Use this target instead for safe, coordinated resets.

# intentional: never calls supabase-start, which calls this target on a stopped stack (COR-253 Pitfall 2).
# intentional: a worktree recreates only its own compose Postgres, never db:wipe, which leaves the shopwired schemas (COR-375/D7).
db-reset-full: ## Full database reset (Supabase auth + Laravel migrations + synthetic seed); supabase-start also runs it on a stopped stack
	@echo "$(YELLOW)=== FULL DATABASE RESET ===$(NC)"
	@echo "$(YELLOW)This resets Supabase auth tables AND re-runs Laravel migrations + the synthetic seed.$(NC)"
	@echo ""
	@echo "$(YELLOW)Preflight: checking the seed guard and generating the seed (no writes)...$(NC)"
	@if $(IN_WORKTREE); then \
		$(DB_RUN) --destructive $(EXEC) artisan dev:seed-fake --dry-run && echo "" && \
		echo "$(YELLOW)Step 1/2: Recreating this worktree's Postgres empty (auth tables re-applied)...$(NC)" && \
		bin/stack db-reset && echo "" && \
		echo "$(YELLOW)Step 2/2: Migrating + running the synthetic seed...$(NC)" && \
		$(DB_RUN) --destructive $(EXEC) artisan dev:seed-fake; \
	else \
		$(EXEC) artisan dev:seed-fake --dry-run && echo "" && \
		echo "$(YELLOW)Step 1/2: Resetting Supabase (auth tables, test users)...$(NC)" && \
		$(MAKE) supabase-reset && echo "" && \
		echo "$(YELLOW)Step 2/2: Migrating + running the synthetic seed...$(NC)" && \
		$(EXEC) artisan dev:seed-fake; \
	fi
	@echo ""
	@echo "$(GREEN)Full database reset complete.$(NC)"
	@echo "$(GREEN)- Supabase auth tables: reset$(NC)"
	@echo "$(GREEN)- Test users: seeded$(NC)"
	@echo "$(GREEN)- Laravel tables: migrated$(NC)"
	@echo "$(GREEN)- Synthetic seed: applied (no sync jobs, no external calls)$(NC)"

seed-fake: ## Seed the synthetic, production-shaped data (add ARGS=--fresh to replace an existing seed)
	@$(DB_RUN) --destructive $(EXEC) artisan dev:seed-fake $(ARGS)

# =============================================================================
# Supabase (Local Development)
# =============================================================================
# Requires ALZ_ADMIN env var pointing to alz-admin project
# Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin

supabase-start: ## Start Supabase services; a stopped stack is started then reset from empty (make db-reset-full)
ifndef ALZ_ADMIN
	$(error ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin)
endif
	@$(REFUSE_IN_WORKTREE)
ifneq ($(CI),true)
	@if (cd $(ALZ_ADMIN) && pnpm exec supabase status >/dev/null 2>&1); then \
		echo "Supabase already running"; \
	else \
		(cd $(ALZ_ADMIN) && pnpm exec supabase start) && \
		$(MAKE) db-reset-full; \
	fi
endif

supabase-functions: ## Start Supabase Edge Functions dev server (long-running)
ifndef ALZ_ADMIN
	$(error ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin)
endif
	cd $(ALZ_ADMIN) && pnpm exec supabase functions serve --no-verify-jwt

supabase-stop: ## Stop Supabase services
	@$(REFUSE_IN_WORKTREE)
ifdef ALZ_ADMIN
	cd $(ALZ_ADMIN) && pnpm exec supabase stop
endif

supabase-status: ## Check Supabase status
ifdef ALZ_ADMIN
	cd $(ALZ_ADMIN) && pnpm exec supabase status
endif

supabase-reset: ## Reset Supabase DB, regenerate types, seed test users
ifndef ALZ_ADMIN
	$(error ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin)
endif
	@$(REFUSE_IN_WORKTREE)
	@echo "$(YELLOW)Running full Supabase reset...$(NC)"
	cd $(ALZ_ADMIN) && ALZ_CORE_PATH="$(CURDIR)" pnpm db:setup-local
	@echo "$(GREEN)Supabase reset complete. Database ready with test users.$(NC)"

supabase-seed-users: ## Seed test users only (no DB reset)
ifndef ALZ_ADMIN
	$(error ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin)
endif
	@$(REFUSE_IN_WORKTREE)
	@echo "$(YELLOW)Seeding test users...$(NC)"
	cd $(ALZ_ADMIN) && pnpm tsx scripts/seed-test-users.ts
	@echo "$(GREEN)Test users seeded.$(NC)"

# Redis (Docker - used alongside Supabase PostgreSQL)
redis: ## Start Redis (compose.yaml; local Supabase provides PostgreSQL)
	docker compose up -d redis

redis-recreate: ## Recreate Redis container (fixes dropped host-port bindings)
	docker compose up -d --force-recreate redis

# Development Tools
serve: ## Bring this worktree's stack up (app, Horizon worker, Redis, own Postgres) and print its port
	bin/stack up

db-up: ## Start only this worktree's Postgres and migrate it (host Pest, migrate, seed)
	bin/stack db-up

stack-down: ## Take this worktree's stack down (containers, volumes, network, images)
	bin/stack down

stack-logs: ## Follow the stack app's log (bin/stack logs <service> for another)
	bin/stack logs

pail: ## Tail logs (respects LOG_LEVEL from .env, defaults to info)
	@LOG_LEVEL=$$(grep -E '^LOG_LEVEL=' .env 2>/dev/null | cut -d'=' -f2 | tr -d '"'"'" || echo "info"); \
	php artisan pail --timeout=0 --level=$${LOG_LEVEL:-info}

# IDE Helper
ide-helper: ## Generate IDE helper files
	@echo "$(MODE)"
	$(DB_RUN) $(EXEC) -d xdebug.mode=off artisan ide-helper:generate
	$(DB_RUN) $(EXEC) -d xdebug.mode=off artisan ide-helper:models --nowrite
	$(DB_RUN) $(EXEC) -d xdebug.mode=off artisan ide-helper:meta

# Composite Commands
check: ## Run all quality checks + tests
	@echo "$(MODE)"
	@$(MAKE) lint-full
	@$(MAKE) test

