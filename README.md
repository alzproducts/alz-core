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
- Laravel Octane with Swoole (application server)
- PostgreSQL via Supabase (production) / Docker PostgreSQL (development)
- Redis (cache, queues, sessions)
- Laravel Horizon (queue monitoring)
- Laravel Telescope (debugging)

## Development Setup

### Prerequisites

- **PHP 8.4+** via Homebrew with extensions: `redis`, `swoole`, `pdo_pgsql`
- **Docker Desktop** (for PostgreSQL + Redis services)
- Git

#### macOS PHP Setup

```bash
# Install PHP 8.4
brew install php@8.4
brew link php@8.4 --force

# Install required extensions
pecl install redis swoole

# Verify
php -v          # Should show 8.4.x
php -m | grep redis   # Should show 'redis'
php -m | grep swoole  # Should show 'swoole'
```

### First-Time Setup

```bash
# Clone repository
git clone <repo-url>
cd alz-core

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Start Docker services (PostgreSQL + Redis)
docker compose up -d

# Create databases
make db-setup

# Run migrations
php artisan migrate

# Run unit tests (fast, no external deps)
make test-unit

# Run all tests including integration
make test

# Generate Google Ads API refresh token (one-time)
# 1. Add http://localhost to Authorized redirect URIs in Google Cloud Console
# 2. Open this URL in browser (replace CLIENT_ID with your GOOGLE_ADS_CLIENT_ID):
#    https://accounts.google.com/o/oauth2/v2/auth?client_id=CLIENT_ID&redirect_uri=http://localhost&response_type=code&scope=https://www.googleapis.com/auth/adwords&access_type=offline&prompt=consent
# 3. After authorizing, copy the 'code' parameter from the redirect URL
# 4. Exchange code for refresh token:
curl -X POST https://oauth2.googleapis.com/token \
  -d "code=YOUR_AUTH_CODE" \
  -d "client_id=YOUR_CLIENT_ID" \
  -d "client_secret=YOUR_CLIENT_SECRET" \
  -d "redirect_uri=http://localhost" \
  -d "grant_type=authorization_code"
# 5. Copy refresh_token from response to .env as GOOGLE_ADS_REFRESH_TOKEN
```

### Daily Development

```bash
# Start Docker services (if not running)
docker compose up -d

# Start Octane server (in separate terminal)
php artisan octane:start

# Or use watch mode (auto-reloads on file changes)
php artisan octane:start --watch

# Run unit tests (~5 seconds, recommended)
make test-unit

# Run all tests including integration
make test

# Run linters (before commit)
make lint

# Stop services (when done)
docker compose down
```

**Important**: Code changes require Octane reload (unless using `--watch` mode):
```bash
php artisan octane:reload
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
- **Deploy Command**: `php artisan migrate --force && php artisan config:cache && php artisan route:cache`
- **Start Command**: `php artisan octane:start --server=swoole --host=0.0.0.0 --port=${PORT:-8000}`
- **Health Check Path**: `/up`
- **Health Check Timeout**: 300 seconds

**Settings → Environment**
```
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=errorlog
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Octane Configuration
OCTANE_SERVER=swoole
OCTANE_WORKERS=4
OCTANE_TASK_WORKERS=6
OCTANE_MAX_REQUESTS=500
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

**Laravel Octane**: We use Laravel Octane with Swoole for improved performance. Octane keeps the application in memory across requests, eliminating bootstrap overhead. This is the modern standard for Laravel deployments regardless of scale.

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