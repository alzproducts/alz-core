.PHONY: help install up down shell migrate db-reset-full pint pint-test test test-quick test-coverage coverage-html pest-mutate test-ai test-mutate lint lint-sequential lint-full fix analyse phparkitect deptrac tlint tlint-full psalm psalm-ci psalm-baseline stan rector rector-dry-run refactor check ide-helper test-domain test-domain-coverage test-app test-app-coverage mutate-domain mutate-app supabase-start supabase-functions supabase-stop supabase-status supabase-reset supabase-seed-users redis serve pail

# Enable strict shell mode for robust error handling
SHELL := bash
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
SAIL := ./vendor/bin/sail
SAIL_CONTAINER_NAME ?= laravel.test
COMPOSER_IMAGE ?= laravelsail/php84-composer:latest

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
else ifeq ($(shell docker ps -q -f name=$(SAIL_CONTAINER_NAME) 2>/dev/null),)
	# Sail not running - use native commands (fallback)
	EXEC = php
	COMPOSER = composer
	MODE = $(YELLOW)[Native Mode - Sail not running]$(NC)
else
	# Sail is running - use Sail commands
	EXEC = $(SAIL) php
	COMPOSER = $(SAIL) composer
	MODE = $(GREEN)[Sail Mode]$(NC)
endif

help: ## Show this help message
	@echo "$(GREEN)Available commands:$(NC)"
	@awk 'BEGIN {FS = ":.*##"; printf "\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  $(BLUE)%-20s$(NC) %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo ""
	@echo "Mode: $(MODE)"

# Installation & Setup
install: ## First-time project setup
	@echo "Running installation in $(MODE)"
	@echo "$(GREEN)Installing dependencies...$(NC)"
	@if [ ! -d "vendor" ]; then \
		docker run --rm -u "$$(id -u):$$(id -g)" \
			-v "$$(pwd):/var/www/html" \
			-w /var/www/html \
			"$(COMPOSER_IMAGE)" \
			composer install --ignore-platform-reqs || exit 1; \
	fi
	@if [ ! -f ".env" ]; then \
		cp .env.example .env && \
		echo "$(GREEN).env file created$(NC)"; \
	fi
	@if [ "$(CI)" != "true" ] && [ "$(IN_DOCKER)" != "yes" ]; then \
		if [ ! -x "$(SAIL)" ]; then \
			echo "$(YELLOW)Error: Sail not found at $(SAIL)$(NC)"; \
			echo "$(YELLOW)Installation may have failed. Check vendor directory.$(NC)"; \
			exit 1; \
		fi; \
		$(SAIL) up -d && \
		$(SAIL) artisan key:generate && \
		$(SAIL) artisan migrate && \
		echo "$(GREEN)Setup complete! Access: http://localhost$(NC)"; \
	fi

# Sail Container Management
up: ## Start Sail containers
	@if [ "$(CI)" = "true" ] || [ "$(IN_DOCKER)" = "yes" ]; then \
		echo "$(YELLOW)Cannot start Sail from CI or inside container$(NC)"; \
		exit 1; \
	fi
	@if [ ! -x "$(SAIL)" ]; then \
		echo "$(YELLOW)Error: Sail not found at $(SAIL)$(NC)"; \
		echo "$(YELLOW)Run 'make install' first$(NC)"; \
		exit 1; \
	fi
	@echo "$(MODE)"
	$(SAIL) up -d

down: ## Stop Sail containers
	@if [ "$(CI)" = "true" ] || [ "$(IN_DOCKER)" = "yes" ]; then \
		echo "$(YELLOW)Cannot stop Sail from CI or inside container$(NC)"; \
		exit 1; \
	fi
	@if [ ! -x "$(SAIL)" ]; then \
		echo "$(YELLOW)Error: Sail not found at $(SAIL)$(NC)"; \
		echo "$(YELLOW)Run 'make install' first$(NC)"; \
		exit 1; \
	fi
	@echo "$(MODE)"
	$(SAIL) down

shell: ## Access container shell
	@if [ "$(CI)" = "true" ] || [ "$(IN_DOCKER)" = "yes" ]; then \
		echo "$(YELLOW)Already in shell or CI environment$(NC)"; \
		exit 1; \
	fi
	@if [ ! -x "$(SAIL)" ]; then \
		echo "$(YELLOW)Error: Sail not found at $(SAIL)$(NC)"; \
		echo "$(YELLOW)Run 'make install' first$(NC)"; \
		exit 1; \
	fi
	@echo "$(MODE)"
	$(SAIL) shell

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
	 E1=0; E2=0; E3=0; E4=0; E5=0; \
	 wait $$P1 || E1=$$?; wait $$P2 || E2=$$?; wait $$P3 || E3=$$?; wait $$P4 || E4=$$?; wait $$P5 || E5=$$?; \
	 echo "=== Pint ===" && cat /tmp/alz-lint/1-pint.txt; \
	 echo "=== PHPStan ===" && cat /tmp/alz-lint/2-phpstan.txt; \
	 echo "=== PHPArkitect ===" && cat /tmp/alz-lint/3-phparkitect.txt; \
	 echo "=== Deptrac ===" && cat /tmp/alz-lint/4-deptrac.txt; \
	 echo "=== TLint ===" && cat /tmp/alz-lint/5-tlint.txt; \
	 rm -rf /tmp/alz-lint; \
	 [ $$E1 -eq 0 ] && [ $$E2 -eq 0 ] && [ $$E3 -eq 0 ] && [ $$E4 -eq 0 ] && [ $$E5 -eq 0 ]

lint-sequential: ## Run sequential lint (Pint + PHPStan + PHPArkitect + Deptrac + TLint)
	@echo "$(MODE)"
	@$(MAKE) pint-test
	@$(MAKE) analyse
	@$(MAKE) phparkitect
	@$(MAKE) deptrac
	@$(MAKE) tlint

lint-full: ## Run full linting (Pint + PHPStan + PHPArkitect + Deptrac + TLint + Psalm)
	@echo "$(MODE)"
	@$(MAKE) pint-test
	@$(MAKE) analyse
	@$(MAKE) phparkitect
	@$(MAKE) deptrac
	@$(MAKE) tlint-full
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
test: ## Run full Pest test suite (all layers, excludes tests that send real Slack messages)
	@echo "$(MODE)"
	$(EXEC) vendor/bin/pest --parallel --exclude-group=slack

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
	$(EXEC) vendor/bin/pest --testsuite=Application --parallel

test-app-coverage: ## Run Application tests with 70% coverage (App code only)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=coverage vendor/bin/pest --configuration=phpunit-app.xml --coverage --min=70

test-coverage: ## Run Domain (90%) + Application (70%) coverage checks in parallel - PR gate
	@echo "$(YELLOW)Running layer coverage checks in parallel...$(NC)"
	@$(MAKE) -j2 test-domain-coverage test-app-coverage
	@echo "$(GREEN)✓ All layer coverage thresholds passed (Domain 90%, Application 70%)$(NC)"

test-coverage-ci: ## Generate coverage.xml for CI/Codecov upload (all tests)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=coverage vendor/bin/pest --coverage-clover=coverage.xml

coverage-html: ## Generate HTML coverage report (open coverage-report/index.html)
	@echo "$(MODE)"
	@mkdir -p coverage-report
	$(EXEC) -d xdebug.mode=coverage vendor/bin/pest --coverage-html=coverage-report
	@echo "$(GREEN)Coverage report generated: coverage-report/index.html$(NC)"

pest-mutate: ## Run Pest mutation testing
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/pest --mutate --everything --covered-only --min=85 --parallel --processes=9

# Layer-specific mutation testing (see tests/TestingStrategy.md)

mutate-domain: ## Run Pest mutation testing on Domain layer (90%+ min score)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/pest --mutate \
		--path=app/Domain \
		--ignore=app/Domain/Exceptions \
		--everything --min=90 --parallel --processes=9 \
		--testsuite=Domain --ignore-min-score-on-zero-mutations

mutate-app: ## Run Pest mutation testing on Application layer (70%+ min score)
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off vendor/bin/pest --mutate \
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
	$(EXEC) artisan migrate

# =============================================================================
# Database Reset (Coordinated)
# =============================================================================
# IMPORTANT: This project shares PostgreSQL with Supabase (alz-admin).
# Supabase owns auth.* tables. NEVER run migrate:fresh, migrate:refresh,
# migrate:reset, or db:wipe directly - they destroy Supabase auth tables.
# Use this target instead for safe, coordinated resets.

db-reset-full: ## Full database reset (Supabase auth + Laravel migrations)
	@echo "$(YELLOW)=== FULL DATABASE RESET ===$(NC)"
	@echo "$(YELLOW)This resets Supabase auth tables AND re-runs Laravel migrations.$(NC)"
	@echo ""
	@echo "$(YELLOW)Step 1/2: Resetting Supabase (auth tables, test users)...$(NC)"
	@$(MAKE) supabase-reset
	@echo ""
	@echo "$(YELLOW)Step 2/2: Running Laravel migrations...$(NC)"
	@$(MAKE) migrate
	@echo ""
	@echo "$(GREEN)Full database reset complete.$(NC)"
	@echo "$(GREEN)- Supabase auth tables: reset$(NC)"
	@echo "$(GREEN)- Test users: seeded$(NC)"
	@echo "$(GREEN)- Laravel tables: migrated$(NC)"

# =============================================================================
# Supabase (Local Development)
# =============================================================================
# Requires ALZ_ADMIN env var pointing to alz-admin project
# Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin

supabase-start: ## Start Supabase services (PostgreSQL, Auth, Storage)
ifndef ALZ_ADMIN
	$(error ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin)
endif
ifneq ($(CI),true)
	@cd $(ALZ_ADMIN) && (pnpm exec supabase status 2>/dev/null | grep -qi "running\|started" && echo "Supabase already running" || pnpm exec supabase start)
endif

supabase-functions: ## Start Supabase Edge Functions dev server (long-running)
ifndef ALZ_ADMIN
	$(error ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin)
endif
	cd $(ALZ_ADMIN) && pnpm exec supabase functions serve --no-verify-jwt

supabase-stop: ## Stop Supabase services
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
	@echo "$(YELLOW)Running full Supabase reset...$(NC)"
	cd $(ALZ_ADMIN) && pnpm db:setup-local
	@echo "$(GREEN)Supabase reset complete. Database ready with test users.$(NC)"

supabase-seed-users: ## Seed test users only (no DB reset)
ifndef ALZ_ADMIN
	$(error ALZ_ADMIN not set. Add to ~/.zshrc: export ALZ_ADMIN=/path/to/alz-admin)
endif
	@echo "$(YELLOW)Seeding test users...$(NC)"
	cd $(ALZ_ADMIN) && pnpm tsx scripts/seed-test-users.ts
	@echo "$(GREEN)Test users seeded.$(NC)"

# Redis (Docker - used alongside Supabase PostgreSQL)
redis: ## Start Redis only (not PostgreSQL from compose.yaml)
	docker compose up -d redis

# Development Tools
serve: ## Start full dev environment (Docker + Octane + Queue)
	bin/serve

pail: ## Tail logs (respects LOG_LEVEL from .env, defaults to info)
	@LOG_LEVEL=$$(grep -E '^LOG_LEVEL=' .env 2>/dev/null | cut -d'=' -f2 | tr -d '"'"'" || echo "info"); \
	php artisan pail --timeout=0 --level=$${LOG_LEVEL:-info}

# IDE Helper
ide-helper: ## Generate IDE helper files
	@echo "$(MODE)"
	$(EXEC) -d xdebug.mode=off artisan ide-helper:generate
	$(EXEC) -d xdebug.mode=off artisan ide-helper:models --nowrite
	$(EXEC) -d xdebug.mode=off artisan ide-helper:meta

# Composite Commands
check: ## Run all quality checks + tests
	@echo "$(MODE)"
	@$(MAKE) lint-full
	@$(MAKE) test

