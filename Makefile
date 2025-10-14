.PHONY: help install up down shell migrate fresh test test-coverage test-ai test-mutate lint lint-full fix analyse insights phparkitect rector rector-dry-run refactor check check-full infection infection-fast infection-strict infection-incremental infection-ci ide-helper

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
			$(COMPOSER_IMAGE) \
			composer install --ignore-platform-reqs || exit 1; \
	fi
	@if [ ! -f ".env" ]; then \
		cp .env.example .env && \
		echo "$(GREEN).env file created$(NC)" || exit 1; \
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
		echo "$(GREEN)Setup complete! Access: http://localhost$(NC)" || exit 1; \
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
lint: ## Run quick lint (Pint + PHPStan + PHPArkitect)
	@echo "$(MODE)"
	$(COMPOSER) run lint

lint-full: ## Run full linting (Pint + PHPStan + Insights + PHPArkitect)
	@echo "$(MODE)"
	$(COMPOSER) run lint:full

fix: ## Auto-fix code style with Pint
	@echo "$(MODE)"
	$(COMPOSER) run fix

analyse: ## Run PHPStan Level max static analysis
	@echo "$(MODE)"
	$(COMPOSER) run analyse

insights: ## Run PHP Insights quality check
	@echo "$(MODE)"
	$(COMPOSER) run insights

phparkitect: ## Run PHPArkitect architecture checks
	@echo "$(MODE)"
	$(COMPOSER) run phparkitect

# Refactoring
rector: ## Run Rector refactoring (apply changes)
	@echo "$(MODE)"
	$(COMPOSER) run rector

rector-dry-run: ## Preview Rector changes (dry-run)
	@echo "$(MODE)"
	$(COMPOSER) run rector:dry-run

refactor: ## Run Rector + Pint combo
	@echo "$(MODE)"
	$(COMPOSER) run refactor

# Testing
test: ## Run Pest test suite
	@echo "$(MODE)"
	$(COMPOSER) run test

test-coverage: ## Run tests with 80% coverage requirement
	@echo "$(MODE)"
	$(COMPOSER) run test:coverage

test-ai: ## Validate AI-generated tests with mutation testing
	@echo "$(MODE)"
	$(COMPOSER) run test:ai

test-mutate: ## Run full mutation testing suite
	@echo "$(MODE)"
	$(COMPOSER) run test:mutate

infection: ## Run Infection mutation testing
	@echo "$(MODE)"
	$(COMPOSER) run infection

infection-fast: ## Run Infection with cached coverage (fastest)
	@echo "$(MODE)"
	$(COMPOSER) run infection:fast

infection-strict: ## Run Infection with strict thresholds
	@echo "$(MODE)"
	$(COMPOSER) run infection:strict

infection-incremental: ## Run Infection on changed lines only
	@echo "$(MODE)"
	$(COMPOSER) run infection:incremental

infection-ci: ## Run Infection for CI with GitHub logger
	@echo "$(MODE)"
	$(COMPOSER) run infection:ci

# Database
migrate: ## Run database migrations
	@echo "$(MODE)"
	$(EXEC) artisan migrate

fresh: ## Fresh database with seeders
	@echo "$(MODE)"
	$(EXEC) artisan migrate:fresh --seed

# IDE Helper
ide-helper: ## Generate IDE helper files
	@echo "$(MODE)"
	$(COMPOSER) run ide-helper

# Composite Commands
check: ## Run all quality checks + tests
	@echo "$(MODE)"
	$(COMPOSER) run check

check-full: ## Run full checks including mutation testing
	@echo "$(MODE)"
	$(COMPOSER) run check:full
