# ALZ Core

Backend service for e-commerce webhooks and background jobs. Portfolio piece demonstrating modern Laravel best practices with clean architecture.

## Overview

- **Team**: Solo developer (1 person)
- **Users**: 3-4 internal staff
- **Purpose**: Process webhooks, sync orders/inventory/products, scheduled tasks
- **Frontend**: Separate Next.js app using Supabase (separate repo)
- **Deployment**: Railway (multi-service architecture)

## Tech Stack

- Laravel 12 (backend-only API)
- PHP 8.4+
- PostgreSQL via Supabase (production) / SQLite (development)
- Redis (cache, queues, sessions)
- Laravel Horizon (queue monitoring)
- Laravel Telescope (debugging)

## Development Setup

### Prerequisites

- Docker Desktop (for Laravel Sail)
- Git

### First-Time Setup

```bash
# Clone repository
git clone <repo-url>
cd alz-core

# Install dependencies via Docker (no local PHP needed)
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs

# Start Sail containers
./vendor/bin/sail up -d

# Run migrations
./vendor/bin/sail artisan migrate

# Run tests
./vendor/bin/sail artisan test
```

### Daily Development

```bash
# Start services
./vendor/bin/sail up -d

# Run tests
make test

# Run linters (before commit)
make lint

# Stop services
./vendor/bin/sail down
```

## Code Quality Standards

We maintain strict code quality with automated linting:

- **Laravel Pint** - Code style (PER preset with strict rules)
- **PHPStan Level max** - Static analysis with bleeding edge features
- **PHP Insights** - Architecture and complexity metrics
- **PHPArkitect** - Clean Architecture layer enforcement

```bash
# Fast linting (pre-commit) - ~5-10 seconds
make lint

# Full linting (pre-push) - ~20-30 seconds
make lint-full

# Run everything (tests + linters)
make check

# Alternative: via composer (delegates to make)
composer run lint
composer run check
```

Git hooks automatically enforce these standards on commit/push.

## Railway Deployment

**Deployment Architecture**: "Majestic Monolith" - one codebase, multiple Railway services.

### Service Configuration

All configuration is done via Railway Dashboard UI (Settings persist across deployments).

#### Service 1: Web (Laravel App)

**Settings → Deploy**
- **Source**: Connect to GitHub repository
- **Deploy Command**: `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache`
- **Start Command**: Leave blank (Railway auto-detects Laravel and uses nginx + php-fpm)
- **Health Check Path**: `/health`
- **Health Check Timeout**: 300 seconds

**Settings → Environment**
```
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=errorlog
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis
```

#### Service 2: Worker (Horizon Queue)

**Settings → Deploy**
- **Source**: Same GitHub repository as Web service
- **Start Command**: `php artisan horizon`

**Settings → Environment**
- Share all environment variables with Web service

**Settings → Deploy**
- **Restart Policy**: `ON_FAILURE`
- **Restart Max Retries**: `3`

#### Service 3: Redis

**Create from Template**
- Railway automatically provisions Redis
- Connection details auto-injected as `REDIS_URL` to Web and Worker services

#### Service 4: PostgreSQL Database

**Use Supabase Integration**
- Shared database with Next.js frontend
- Configure `DATABASE_URL` environment variable in Web and Worker services

### Health Check Endpoint

The `/health` endpoint is required for zero-downtime deployments:

```php
// routes/web.php
Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});
```

### Deployment Workflow

1. **One-Time Setup**: Configure services in Railway Dashboard (as documented above)
2. **Every Push**: `git push origin main` → Railway auto-deploys both Web and Worker services

Railway remembers all UI configuration. No config files needed.

### Why No railway.toml or Procfile?

Railway's 2025 best practice is **configuration via UI**, not config files:

- ✅ **Railway UI**: Source of truth for deployment settings
- ❌ **railway.toml**: Deprecated (Nixpacks in maintenance mode, `php artisan serve` not production-ready)
- ❌ **Procfile**: Ignored in multi-service architecture

**Benefits of UI Configuration**:
- Each service can point to different branches
- Independent scaling and environment variables per service
- Deployment settings don't clutter codebase
- Secrets never touch repository

### Architecture Notes

**PHP-FPM vs Laravel Octane**: We use Railway's auto-configured nginx + php-fpm. Octane is overkill for 3-4 internal users. Consider Octane only if profiling shows request bootstrapping bottlenecks.

**Separate Services**: Web and Worker run as separate Railway services for:
- Independent scaling (scale web without scaling worker)
- Different resource profiles (HTTP concurrency vs long-running processes)
- Deployment safety (worker failures don't affect web server)

**Scheduler (Phase 2)**: No cron service yet (YAGNI). Will use Railway cron jobs in Phase 2 when scheduled tasks are defined.

## Project Documentation

- **Project Plan**: `.ai/docs/plans/alz-core-initial-plan.md`
- **Deferred Decisions**: `.ai/docs/plans/alz-core-deferred-decisions.md`
- **Development Guide**: `CLAUDE.md`

## License

This project is proprietary. All rights reserved.