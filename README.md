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
- PostgreSQL via Supabase (production + local dev)
- Redis (cache, queues, sessions)
- Laravel Horizon (queue monitoring)
- Laravel Telescope (debugging)

## Development Setup

### Prerequisites

- **PHP 8.4+** via Homebrew with extensions: `redis`, `swoole`, `pdo_pgsql`
- **Docker Desktop** (for Redis)
- **Node.js + pnpm** (for Supabase CLI in alz-admin)
- **alz-admin** repo cloned (contains Supabase config)
- Git

**Shell configuration** (add to `~/.zshrc`):
```bash
export ALZ_ADMIN=/path/to/alz-admin
```

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

# Reset Supabase and seed test users
# (Runs in alz-admin: resets DB, regenerates types, seeds users)
make supabase-reset

# Start Redis
make redis

# Run Laravel migrations (adopts existing Supabase schema)
php artisan migrate

# Verify tests pass
make test
```

### Daily Development

```bash
# Start Supabase (if not running)
make supabase-start

# Start Redis (if not running)
make redis

# Start Octane server with hot reload
php artisan octane:start --watch

# In another terminal: start queue worker
php artisan queue:listen -v --timeout=3600 --queue=high,default,low

# Optional: start edge functions (for auth flows)
make supabase-functions
```

### Database Modes

This project uses **two different database setups** depending on context:

| Mode | Database | Port | Managed By | When Used |
|------|----------|------|------------|-----------|
| **Local Dev** | Supabase PostgreSQL | 54322 | alz-admin project | Daily development |
| **CI/Testing** | Docker PostgreSQL | 5432 | GitHub Actions services | CI pipeline |

**Local development** connects to Supabase (shared with alz-admin frontend). User authentication, profiles, and roles are managed by Supabase Auth.

**CI/Testing** uses isolated Docker PostgreSQL with mocked auth schema. This allows tests to run without Supabase dependencies.

**Database Commands** (Local Development):
```bash
make supabase-status      # Check if Supabase is running
make supabase-reset       # Full reset: wipe DB, seed data, create test users
make supabase-seed-users  # Seed test users only (no DB wipe)
make migrate              # Run Laravel migrations (safe, additive)
make supabase-stop        # Stop Supabase when done
```

**Testing & Linting**:
```bash
make test              # Run all tests
make test-quick        # Domain tests only (~5s)
make lint              # Run linters (before commit)
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

#### Service 1: Web (`alz-core-web`)

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

#### Service 2: Worker (`alz-core-worker`)

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

**Scheduler**: The `alz-core-scheduler` service runs `php artisan schedule:work` to execute scheduled tasks defined in `routes/console.php`.

### SSH Quick Reference

```bash
# List available services
railway service list

# SSH into a service
railway ssh -s alz-core-worker "php artisan tinker --execute=\"...\""

# Dispatch jobs manually
railway ssh -s alz-core-worker "php artisan tinker --execute=\"App\\Presentation\\Jobs\\Shopwired\\SyncShopwiredCustomersJob::dispatch();\""

# Check Horizon status
railway ssh -s alz-core-worker "php artisan horizon:status"
```

## Project Documentation

- **Project Plan**: `.ai/plans/alz-core-initial-plan.md`
- **Deferred Decisions**: `.ai/plans/alz-core-deferred-decisions.md`
- **Development Guide**: `CLAUDE.md`

## License

This project is proprietary. All rights reserved.
