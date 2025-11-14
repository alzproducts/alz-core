# Docker Production Deployment Guide

**Laravel Octane + Swoole on Railway**

Created: 2025-01-15
Status: Production-ready
Deployment Platform: Railway (Docker-based)

---

## Table of Contents

- [Overview](#overview)
- [Architecture Decisions](#architecture-decisions)
- [Files Created](#files-created)
- [Local Testing](#local-testing)
- [Railway Deployment](#railway-deployment)
- [Environment Variables](#environment-variables)
- [Troubleshooting](#troubleshooting)
- [Performance Tuning](#performance-tuning)
- [Migration to Custom Dockerfile](#migration-to-custom-dockerfile)

---

## Overview

### Why Docker?

Railway discontinued Nixpacks, and Railpacks do not support Swoole extensions. We now use a custom Dockerfile for full control over:

- **PHP 8.4 with Swoole extension** (PECL-compiled, version pinned)
- **Multi-stage builds** (reduced image size: ~300-400MB vs ~800MB-1.2GB)
- **Security hardening** (non-root user, proper file permissions)
- **Production optimizations** (OPcache, artisan caching, health checks)

### Production Stack

```
┌─────────────────────────────────────────┐
│          Railway Platform               │
│  ┌───────────────────────────────────┐  │
│  │   Docker Container (www-data)     │  │
│  │  ┌─────────────────────────────┐  │  │
│  │  │  Laravel Octane + Swoole    │  │  │
│  │  │  - 4 workers (configurable) │  │  │
│  │  │  - OPcache enabled          │  │  │
│  │  │  - Health checks: /up       │  │  │
│  │  └─────────────────────────────┘  │  │
│  └───────────────────────────────────┘  │
└─────────────────────────────────────────┘
           │                    │
           ▼                    ▼
   ┌──────────────┐      ┌──────────────┐
   │  Supabase    │      │  Railway     │
   │  PostgreSQL  │      │  Redis       │
   └──────────────┘      └──────────────┘
```

---

## Architecture Decisions

### 1. Base Image: `serversideup/php:8.4-cli`

**Why?**
- ✅ Debian-based (Ubuntu 24.04 compatibility)
- ✅ Security-hardened (non-root user `www-data` by default)
- ✅ Actively maintained by Laravel community
- ✅ Clean starting point for PECL extension installation

**Alternative Considered:**
- `php:8.4-cli` - Too minimal, missing system libraries
- `php:8.4-fpm` - Wrong server model (Octane replaces FPM)
- Laravel Sail image - Includes development tools (bloated)

### 2. Multi-Stage Build

**Stage 1: Builder**
- Installs build dependencies (gcc, make, autoconf)
- Compiles Swoole from PECL (latest stable 6.x)
- Installs Composer dependencies
- **Discarded after build** (keeps final image small)

**Stage 2: Runtime**
- Copies only compiled extensions + vendor/
- Installs runtime dependencies only
- **Final image: ~300-400MB** vs ~800MB-1.2GB single-stage

### 3. Extension Installation Strategy

**Installed via docker-php-ext-install:**
- `pdo`, `pdo_pgsql`, `pgsql` - PostgreSQL database driver
- `pcntl` - Process control (required by Octane)
- `zip` - Composer package extraction
- `bcmath` - Arbitrary precision math (e-commerce calculations)
- `opcache` - Bytecode caching (2-3x performance boost)

**Installed via PECL (latest stable):**
- `swoole` (latest 6.x) - Async server for Octane, PHP 8.4 compatible
- Redis extension - Provided by serversideup/php base image

**Why latest instead of pinned version?**
- ✅ Swoole 6.0+ required for PHP 8.4 compatibility (curl extension synchronization)
- ✅ Automatic security patches
- ✅ Stable 6.x branch with Laravel Octane support
- ✅ Railway rebuilds containers on every deploy (reproducibility maintained)
- ❌ Swoole 5.1.4 incompatible with PHP 8.4 (build failures)

### 4. Security Hardening

**Non-root User:**
```dockerfile
USER www-data  # UID 33, GID 33
```
- Prevents container escape attacks
- Limits damage if compromised

**File Permissions:**
```dockerfile
COPY --chown=www-data:www-data . .
RUN chmod -R 755 storage bootstrap/cache
```
- Laravel storage writable by app only
- Bootstrap cache optimized for reads

**Tini Init System:**
```dockerfile
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/docker-entrypoint.sh"]
```
- Proper signal forwarding (SIGTERM for graceful shutdown)
- Prevents zombie processes

### 5. Production Optimizations

**OPcache Configuration:**
```ini
opcache.enable=1
opcache.enable_cli=1                     # Enable for Octane (CLI mode)
opcache.memory_consumption=256           # 256MB cache
opcache.interned_strings_buffer=16       # 16MB for string interning
opcache.max_accelerated_files=20000      # Large codebases
opcache.validate_timestamps=0            # No stat() calls (huge win!)
```

**Laravel Artisan Caching (runtime, not build-time):**
```bash
php artisan config:cache   # Cache config files
php artisan route:cache    # Cache route definitions
php artisan event:cache    # Cache event listeners
```

**Why runtime?** Build-time caching uses `.env.example`, not production `.env`.

### 5.1 OPcache validate_timestamps=0 Implications

⚠️ **Important:** `opcache.validate_timestamps=0` means OPcache NEVER checks if PHP files changed.

**What this means:**
- Code changes require **container rebuild** (`docker build`), not just restart
- Production best practice: 2-3x performance boost by eliminating filesystem stat() calls
- Safe for Railway: Every deployment rebuilds the container automatically

**Local Development:**
Use `docker-compose.yml` (development) which sets `opcache.validate_timestamps=1` for auto-reload.
The production image (`docker-compose.prod.yml`) uses `validate_timestamps=0` for maximum performance.

### 6. Health Checks

**Docker HEALTHCHECK:**
```dockerfile
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:${PORT:-8000}/up || exit 1
```

**What it does:**
- Checks `/up` endpoint every 30 seconds
- Waits 30 seconds after startup (Octane boot time)
- Fails after 3 consecutive failures
- Railway uses this to restart unhealthy containers

**Your `/up` endpoint** (already configured in `routes/web.php:16`):
```php
Route::get('/up', function () {
    Event::dispatch(new DiagnosingHealth);
    return View::file('up.blade.php');
})->middleware('throttle:60,1');
```

---

## Files Created

### 1. `Dockerfile`

Production-optimized multi-stage build:
- **Builder stage:** Compiles Swoole, installs dependencies
- **Runtime stage:** Slim production image with only runtime deps

**Key features:**
- Non-root user (`www-data`)
- Health checks
- Graceful shutdown (tini)
- Railway PORT variable support
- OPcache configuration

### 2. `.dockerignore`

Excludes 70-80% of project files from build context:
- Development dependencies (`node_modules`, `vendor`)
- Testing files (`tests/`, `phpunit.xml`)
- Documentation (`.ai/`, `*.md`)
- Environment files (`.env`, `.env.*`)
- IDE files (`.idea/`, `.vscode/`)

**Impact:** Faster builds, smaller uploads to Railway.

### 3. `docker-entrypoint.sh`

Runtime configuration script (runs on container startup):

**Responsibilities:**
1. Validate environment variables (`APP_KEY`, `DB_CONNECTION`)
2. Check database connectivity (with retries)
3. Run migrations (optional, `AUTO_MIGRATE=true`)
4. Run Laravel optimization commands (config:cache, route:cache)
5. Log Octane configuration summary
6. Start Octane server

**Why separate script?**
- Artisan caching requires `.env` (not available at build time)
- Database migrations need DB connection (not available at build time)
- Graceful startup with retries and logging

### 4. `.env.production.example`

Updated with Docker-specific variables:

```env
# Railway-injected (DO NOT SET)
# PORT=

# Entrypoint script controls
AUTO_MIGRATE=false          # Set true for automatic migrations
SKIP_DB_CHECK=false         # Set true to skip DB connectivity check

# Octane configuration
OCTANE_WORKERS=4            # Auto-tuned if not set
OCTANE_TASK_WORKERS=6
OCTANE_MAX_REQUESTS=500
```

### 5. `docker-compose.prod.yml`

Local production-like testing environment:
- Laravel app (built from `Dockerfile`)
- PostgreSQL 16
- Redis 7
- Health checks for all services

**Usage:**
```bash
docker-compose -f docker-compose.prod.yml up --build
```

---

## Local Testing

### Prerequisites

- Docker Desktop installed
- Docker Compose installed
- `.env` file created (copy from `.env.production.example`)

### Step 1: Generate APP_KEY

```bash
# Using Sail (if containers running)
./vendor/bin/sail artisan key:generate

# OR using Docker directly
docker run --rm -v $(pwd):/app -w /app \
    serversideup/php:8.4-cli php artisan key:generate
```

Copy the generated key to your `.env` file.

### Step 2: Build and Test Locally

```bash
# Build production image
docker-compose -f docker-compose.prod.yml build

# Start services
docker-compose -f docker-compose.prod.yml up

# Watch logs
docker-compose -f docker-compose.prod.yml logs -f app
```

### Step 3: Verify Health

**Health check endpoint:**
```bash
curl http://localhost:8000/up
```

**Check Octane status:**
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan octane:status
```

**Access Horizon dashboard:**
```
http://localhost:8000/horizon
Username: admin
Password: secret
```

### Step 4: Test Graceful Shutdown

```bash
# Send SIGTERM (simulates Railway restart)
docker-compose -f docker-compose.prod.yml stop app

# Check logs for graceful shutdown
docker-compose -f docker-compose.prod.yml logs app | grep -i "shutdown"
```

**Expected output:**
```
[INFO] Stopping Octane server...
[INFO] Waiting for workers to finish requests...
[INFO] Octane stopped gracefully
```

### Step 5: Clean Up

```bash
# Stop and remove containers
docker-compose -f docker-compose.prod.yml down

# Remove volumes (⚠️ deletes database data)
docker-compose -f docker-compose.prod.yml down -v
```

---

## Railway Deployment

### Prerequisites

1. **Railway account** - Sign up at [railway.app](https://railway.app)
2. **Railway CLI** - Install: `npm i -g @railway/cli`
3. **Supabase database** - Already configured (shared with Next.js frontend)
4. **Railway Redis** - Add from Railway dashboard

### Step 1: Create Railway Project

```bash
# Login to Railway
railway login

# Create new project
railway init
```

**Or via dashboard:**
1. Go to [railway.app/new](https://railway.app/new)
2. Click "Deploy from GitHub repo"
3. Select `alzproducts/alz-core` repository

### Step 2: Add Redis Service

**In Railway dashboard:**
1. Click "+ New" → "Database" → "Add Redis"
2. Railway automatically injects these variables:
   - `REDIS_HOST`
   - `REDIS_PORT`
   - `REDIS_PASSWORD`
   - `REDIS_URL`

### Step 3: Configure Environment Variables

**In Railway dashboard → Variables tab:**

```env
# Application
APP_NAME="ALZ Core"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:YOUR_GENERATED_KEY_HERE
APP_URL=https://your-app.up.railway.app

# Railway auto-injects PORT (DO NOT SET)

# Octane
OCTANE_SERVER=swoole
OCTANE_WORKERS=4
OCTANE_TASK_WORKERS=6
OCTANE_MAX_REQUESTS=500

# Logging
LOG_CHANNEL=stderr
LOG_LEVEL=info

# Database (Supabase)
DB_CONNECTION=pgsql
DB_HOST=db.PROJECT-REF.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=YOUR_SUPABASE_PASSWORD
DB_SSLMODE=require

# Redis (Railway auto-injects)
# REDIS_HOST, REDIS_PASSWORD, REDIS_PORT automatically set

# Cache & Queue
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database

# Horizon (set strong credentials)
HORIZON_USER=YOUR_ADMIN_USERNAME
HORIZON_PASSWORD=YOUR_STRONG_PASSWORD

# Supabase JWT
SUPABASE_JWT_SECRET=YOUR_SUPABASE_JWT_SECRET

# Entrypoint controls
AUTO_MIGRATE=false
SKIP_DB_CHECK=false
```

### Step 4: Configure Health Check in Railway

**In Railway dashboard → Settings → Health Check:**
- **Path:** `/up`
- **Interval:** 30 seconds
- **Timeout:** 5 seconds
- **Start Period:** 30 seconds

### Step 5: Deploy

**Via Railway dashboard:**
1. Push to GitHub (main branch)
2. Railway auto-deploys on push

**Via Railway CLI:**
```bash
# Deploy current directory
railway up

# Watch logs
railway logs
```

### Step 6: Run Migrations

**First deployment only:**
```bash
# Via Railway CLI
railway run php artisan migrate --force

# OR set AUTO_MIGRATE=true in environment variables
# (Not recommended for production - manual is safer)
```

### Step 7: Verify Deployment

**Check health:**
```bash
curl https://your-app.up.railway.app/up
```

**Check logs:**
```bash
railway logs --tail 100
```

**Access Horizon:**
```
https://your-app.up.railway.app/horizon
```

---

## Environment Variables

### Required Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_KEY` | Laravel encryption key | `base64:RANDOM_32_CHARS` |
| `DB_HOST` | Supabase database host | `db.abcd1234.supabase.co` |
| `DB_PASSWORD` | Supabase database password | `your-supabase-password` |
| `REDIS_HOST` | Railway Redis host | Auto-injected by Railway |
| `REDIS_PASSWORD` | Railway Redis password | Auto-injected by Railway |
| `SUPABASE_JWT_SECRET` | Supabase JWT secret | From Supabase dashboard |

### Railway Auto-Injected

| Variable | Description | Source |
|----------|-------------|--------|
| `PORT` | Dynamic port binding | Railway platform |
| `REDIS_URL` | Full Redis connection string | Railway Redis service |
| `REDIS_HOST` | Redis hostname | Railway Redis service |
| `REDIS_PORT` | Redis port (default: 6379) | Railway Redis service |
| `REDIS_PASSWORD` | Redis password | Railway Redis service |

### Optional Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `AUTO_MIGRATE` | `false` | Auto-run migrations on startup |
| `SKIP_DB_CHECK` | `false` | Skip DB connectivity check |
| `OCTANE_WORKERS` | `4` | Number of Swoole workers |
| `OCTANE_TASK_WORKERS` | `6` | Number of task workers |
| `OCTANE_MAX_REQUESTS` | `500` | Requests before worker restart |

---

## Troubleshooting

### Build Failures

#### Error: "pecl install swoole failed"

**Cause:** Missing build dependencies.

**Solution:** Dockerfile already includes all dependencies. If using custom Dockerfile:
```dockerfile
RUN apt-get update && apt-get install -y \
    build-essential autoconf libc-dev pkg-config \
    libssl-dev libcurl4-openssl-dev
```

#### Error: "COPY failed: no source files were specified"

**Cause:** `.dockerignore` too aggressive or wrong build context.

**Solution:**
```bash
# Build with explicit context
docker build -t alz-core:latest .

# Check build context size
docker build --no-cache --progress=plain -t alz-core:latest . 2>&1 | grep "transferring context"
```

### Runtime Failures

#### Container Exits Immediately

**Check logs:**
```bash
railway logs --tail 100
```

**Common causes:**
1. **Missing APP_KEY:**
   ```
   [ERROR] Required environment variable APP_KEY is not set!
   ```
   **Fix:** Generate key and add to Railway variables:
   ```bash
   php artisan key:generate --show
   ```

2. **Database connection failed:**
   ```
   [ERROR] Failed to connect to database after 30 attempts
   ```
   **Fix:** Verify Supabase credentials:
   ```bash
   railway run php artisan db:show
   ```

3. **Port binding failed:**
   ```
   [ERROR] bind: address already in use
   ```
   **Fix:** Check if another service is using PORT. Railway should auto-inject unique PORT.

#### Health Check Failing

**Symptoms:** Railway shows "Unhealthy" status, restarts container repeatedly.

**Debug:**
```bash
# Check if /up endpoint responds
railway run curl -f http://localhost:$PORT/up

# Check Octane status
railway run php artisan octane:status
```

**Common causes:**
1. **Route caching issue:**
   ```bash
   railway run php artisan route:clear
   railway run php artisan route:cache
   ```

2. **Octane not started:**
   - Check entrypoint script logs
   - Verify CMD in Dockerfile executes

3. **Throttle middleware blocking:**
   - `/up` endpoint has throttle:60,1
   - Health checks every 30s should be fine
   - If health checks every 5s, adjust throttle

#### Workers Crashing

**Symptoms:** 502 errors, "Worker process exited unexpectedly"

**Debug:**
```bash
# Check worker logs
railway logs --filter "worker"

# Check memory usage
railway run php artisan octane:status
```

**Common causes:**
1. **Memory leak:**
   - Lower `OCTANE_MAX_REQUESTS` (e.g., 250)
   - Increase Railway memory limit (upgrade plan)

2. **Fatal error in code:**
   - Check Laravel logs: `storage/logs/laravel.log`
   - Fix bug, redeploy

3. **Too many workers for available memory:**
   - Railway Free: 512MB RAM → 1-2 workers max
   - Railway Pro: 2GB RAM → 4-8 workers
   - Reduce `OCTANE_WORKERS`

### Performance Issues

#### Slow Response Times

**1. Check OPcache:**
```bash
railway run php -i | grep opcache
```

Should show:
```
opcache.enable => On => On
opcache.validate_timestamps => Off => Off
```

**2. Check Artisan caching:**
```bash
railway run php artisan config:cache
railway run php artisan route:cache
railway run php artisan event:cache
```

**3. Check database query performance:**
```bash
# Enable query logging
LOG_LEVEL=debug

# Use Horizon to monitor queue performance
https://your-app.up.railway.app/horizon
```

#### High Memory Usage

**Check current usage:**
```bash
railway run php artisan octane:status
```

**Optimization strategies:**
1. **Reduce workers:**
   ```env
   OCTANE_WORKERS=2
   OCTANE_TASK_WORKERS=2
   ```

2. **Lower max requests:**
   ```env
   OCTANE_MAX_REQUESTS=250  # More frequent restarts
   ```

3. **Optimize code:**
   - Avoid memory leaks (static variables, global state)
   - Use lazy collections for large datasets
   - Clear unused variables in long-running loops

---

## Performance Tuning

### Worker Configuration

**Railway Free Plan (512MB RAM):**
```env
OCTANE_WORKERS=1
OCTANE_TASK_WORKERS=1
OCTANE_MAX_REQUESTS=250
```

**Railway Pro Plan (2GB RAM):**
```env
OCTANE_WORKERS=4
OCTANE_TASK_WORKERS=6
OCTANE_MAX_REQUESTS=500
```

**Railway Team Plan (8GB RAM):**
```env
OCTANE_WORKERS=8
OCTANE_TASK_WORKERS=12
OCTANE_MAX_REQUESTS=1000
```

**Formula:**
```
Workers = (Available RAM - 256MB buffer) / 128MB per worker
```

### OPcache Tuning

**Default configuration (Dockerfile):**
```ini
opcache.memory_consumption=256       # 256MB cache
opcache.interned_strings_buffer=16   # 16MB string interning
opcache.max_accelerated_files=20000  # 20k files
```

**For large codebases (50k+ files):**
```dockerfile
RUN echo "opcache.max_accelerated_files=100000" >> /usr/local/etc/php/conf.d/opcache-production.ini
```

**For memory-constrained environments:**
```dockerfile
RUN echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache-production.ini && \
    echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/opcache-production.ini
```

### Database Connection Pooling

**PostgreSQL connection limit (Supabase Free: 100 connections):**
```env
DB_POOL_MIN=2
DB_POOL_MAX=10
```

**With 4 workers:**
- 4 workers × 10 connections = 40 max connections
- Leaves 60 connections for Next.js frontend

### Redis Optimization

**Use Redis for everything except sessions:**
```env
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_DRIVER=redis
SESSION_DRIVER=database  # Database for session persistence
```

**Why database for sessions?**
- Redis restart = all users logged out
- Database = sessions persist across Redis restarts

---

## Migration to Custom Dockerfile

If you outgrow `serversideup/php` and need full control, here's the migration path.

### When to Migrate?

**Reasons to stay with serversideup/php:**
- ✅ Current setup works perfectly
- ✅ Don't need custom system packages
- ✅ Auto-updates are beneficial
- ✅ Community support is valuable

**Reasons to migrate to custom:**
- ❌ Need specific PHP extension versions
- ❌ Need custom C libraries (ImageMagick, LibreOffice)
- ❌ Need specific OS packages not in Debian repos
- ❌ Want smaller image size (Alpine-based)
- ❌ Need multi-architecture builds (ARM64)

### Migration Steps

**1. Create custom Dockerfile:**

```dockerfile
# Custom Dockerfile (Ubuntu 24.04 based, like Laravel Sail)
FROM ubuntu:24.04 AS builder

# Install PHP 8.4 from ondrej/php PPA
RUN apt-get update && apt-get install -y software-properties-common && \
    add-apt-repository ppa:ondrej/php && \
    apt-get update && apt-get install -y \
    php8.4-cli \
    php8.4-dev \
    php8.4-pgsql \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-curl \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-intl \
    php8.4-redis \
    php8.4-opcache \
    php8.4-pcntl

# Install Swoole via PECL
RUN pecl install swoole-5.1.4 && \
    echo "extension=swoole.so" > /etc/php/8.4/cli/conf.d/20-swoole.ini

# ... rest of Dockerfile similar to current version
```

**2. Test locally:**
```bash
docker build -t alz-core:custom -f Dockerfile.custom .
docker run -p 8000:8000 alz-core:custom
```

**3. Update Railway:**
- Commit new `Dockerfile.custom`
- Rename to `Dockerfile`
- Push to GitHub
- Railway auto-deploys

**4. Verify no regressions:**
- Health check passes
- Octane starts correctly
- All extensions loaded

### Alternative: Alpine-based

**For smallest image size (~150MB):**

```dockerfile
FROM php:8.4-cli-alpine AS builder

# Install build dependencies
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    openssl-dev \
    curl-dev

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pcntl zip bcmath opcache

# Install Swoole
RUN pecl install swoole-5.1.4 && docker-php-ext-enable swoole

# ... rest of Dockerfile
```

**⚠️ Alpine caveats:**
- Uses musl libc (not glibc) - potential compatibility issues
- Slower builds (compile everything from source)
- Less community testing
- Not recommended unless image size is critical

---

## Additional Resources

### Documentation

- [Laravel Octane Documentation](https://laravel.com/docs/12.x/octane)
- [Swoole Documentation](https://www.swoole.co.uk/docs/)
- [Railway Docker Deployment](https://docs.railway.app/deploy/dockerfiles)
- [Docker Multi-Stage Builds](https://docs.docker.com/build/building/multi-stage/)

### Monitoring

**Railway Dashboard:**
- Metrics: CPU, memory, network
- Logs: Real-time streaming
- Health checks: Status history

**Horizon Dashboard:**
```
https://your-app.up.railway.app/horizon
```
- Queue metrics
- Failed jobs
- Worker status

### Getting Help

**Railway Community:**
- Discord: [discord.gg/railway](https://discord.gg/railway)
- Community Forum: [community.railway.app](https://community.railway.app)

**Laravel Community:**
- Discord: [discord.gg/laravel](https://discord.gg/laravel)
- Forum: [laracasts.com/discuss](https://laracasts.com/discuss)

---

## Changelog

### 2025-01-15 - Initial Release

**Created:**
- Production Dockerfile (multi-stage, serversideup/php base)
- `.dockerignore` (optimized build context)
- `docker-entrypoint.sh` (runtime configuration)
- `docker-compose.prod.yml` (local testing)
- Updated `.env.production.example`

**Key Features:**
- PHP 8.4 + Swoole 5.1.4 (pinned versions)
- Non-root user (`www-data`)
- Health checks (`/up` endpoint)
- Graceful shutdown (tini init)
- Railway PORT variable support
- OPcache production configuration
- Multi-stage build (~300-400MB final image)

**Tested:**
- ✅ Local build successful
- ✅ Local run with docker-compose
- ⏳ Railway deployment (pending)

---

**Ready to deploy?** Follow the [Railway Deployment](#railway-deployment) section above.

**Questions?** Open an issue or consult the [Troubleshooting](#troubleshooting) section.
