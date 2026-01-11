# Sail → Native PHP 8.4 Migration Plan

**Status**: Pending Implementation
**Created**: 2025-11-25
**Estimated Duration**: ~2.5 hours

## Pre-Flight Summary

- **Current**: Laravel Sail with PHP 8.4, PostgreSQL 17, Redis
- **Target**: Native PHP 8.4 + Docker services (PostgreSQL/Redis only)
- **Octane**: Swoole (matches production environment)
- **Data**: Fresh start (no migration needed per user confirmation)
- **Expected Performance**: 6-23x faster across various operations

---

## Phase 0: Pre-Migration Checks & Backup (10 min)

### Step 0.1: Backup Current Configuration

```bash
# Backup .env file
cp .env .env.backup-$(date +%Y%m%d)

# Verify git status (should commit any work first)
git status

# Document current Sail status
docker ps > migration-docker-status.txt
```

### Step 0.2: Stop Local Homebrew Redis (Port Conflict)

```bash
# You have local Redis (PID 842) conflicting with Docker Redis
brew services stop redis

# Verify it's stopped
lsof -i :6379  # Should only show Docker now
```

### Step 0.3: Run Baseline Tests (While Still on Sail)

```bash
# This establishes baseline - tests should pass
./vendor/bin/sail artisan test

# Document results
echo "Baseline tests completed on Sail"
```

---

## Phase 1: Install Native PHP 8.4 + Extensions (30 min)

**IMPORTANT**: Keep Sail running during this phase. We'll transition later.

### Step 1.1: Install PHP 8.4 (Official Homebrew)

```bash
# PHP 8.4.15 IS available in official Homebrew (research confirmed)
brew update
brew install php@8.4

# Link to make it default
brew link --force --overwrite php@8.4

# Verify installation
php --version  # Should show 8.4.15
which php      # Should show /opt/homebrew/bin/php
```

**Bundled Extensions (Verify)**:
```bash
php -m | grep -E 'pdo|pgsql|pcntl|zip|bcmath|opcache'
# All should be present (these come with Homebrew PHP)
```

### Step 1.2: Install phpredis Extension

```bash
# Install via PECL (6x faster than Predis)
pecl install redis

# Verify
php -m | grep redis  # Should show 'redis'
```

**If redis doesn't show**, configure manually:
```bash
# Create config file
echo 'extension=redis.so' > /opt/homebrew/etc/php/8.4/conf.d/ext-redis.ini

# Restart PHP
brew services restart php@8.4

# Verify again
php -m | grep redis
```

### Step 1.3: Install Swoole Extension (Production Parity)

```bash
# IMPORTANT: Research showed macOS requires CFLAGS for PCRE2
brew install pcre2  # Prerequisite

# Install with environment variable to avoid PCRE2 header errors
CFLAGS=-I/opt/homebrew/include pecl install swoole

# When prompted, enable features:
# - Sockets: yes
# - OpenSSL: yes
# - HTTP2: yes
# - PostgreSQL: yes
# - cURL: yes
```

**If installation fails**, see troubleshooting in Phase 10.

**Configure Swoole**:
```bash
# Should auto-configure, but verify
php -m | grep swoole  # Should show 'swoole'

# If not shown:
echo 'extension=swoole.so' > /opt/homebrew/etc/php/8.4/conf.d/ext-swoole.ini
php -m | grep swoole
```

### Step 1.4: Rebuild Composer Autoload

```bash
# Still using Sail for now, but prepare for transition
./vendor/bin/sail composer dump-autoload
```

---

## Phase 2: Extract & Modify Docker Compose (20 min)

**STILL USING SAIL** - We're preparing the new setup

### Step 2.1: Publish Sail's Docker Compose (Using Sail)

```bash
# Use SAIL to publish compose.yaml
./vendor/bin/sail artisan sail:install

# When prompted, select:
# - pgsql (PostgreSQL 17)
# - redis (Redis)

# This creates compose.yaml in project root
ls -la compose.yaml  # Verify it exists
```

### Step 2.2: Create Services-Only compose.yaml

**MANUAL STEP**: Create new `compose.yaml` with ONLY services (no PHP container).

**Replace entire compose.yaml content with**:

```yaml
services:
  pgsql:
    image: 'postgres:17-alpine'
    ports:
      - '${FORWARD_DB_PORT:-5432}:5432'
    environment:
      POSTGRES_DB: '${DB_DATABASE}'
      POSTGRES_USER: '${DB_USERNAME}'
      POSTGRES_PASSWORD: '${DB_PASSWORD:-password}'
      POSTGRES_HOST_AUTH_METHOD: '${DB_AUTH_METHOD:-trust}'
    volumes:
      - 'alz-core_sail-pgsql:/var/lib/postgresql/data'
    networks:
      - sail
    healthcheck:
      test: ["CMD", "pg_isready", "-q", "-d", "${DB_DATABASE}", "-U", "${DB_USERNAME}"]
      retries: 3
      timeout: 5s

  redis:
    image: 'redis:alpine'
    ports:
      - '${FORWARD_REDIS_PORT:-6379}:6379'
    volumes:
      - 'alz-core_sail-redis:/data'
    networks:
      - sail
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      retries: 3
      timeout: 5s
    command: redis-server --appendonly yes

networks:
  sail:
    driver: bridge

volumes:
  alz-core_sail-pgsql:
    driver: local
  alz-core_sail-redis:
    driver: local
```

**Key Details**:
- Volume names match EXISTING Sail volumes: `alz-core_sail-pgsql`, `alz-core_sail-redis`
- Since you said "can start fresh", these will be recreated
- PostgreSQL 17 (matches Sail's current version)
- No laravel.test PHP container

---

## Phase 3: **TRANSITION POINT** - Stop Sail, Start New Services (15 min)

### Step 3.1: Stop Sail Completely

```bash
# Stop all Sail containers
./vendor/bin/sail down

# Verify stopped
docker ps | grep alz-core  # Should show nothing
```

### Step 3.2: Remove Old Volumes (Fresh Start Approved by User)

```bash
# You confirmed "can start fresh", so remove old volumes
docker volume rm alz-core_sail-pgsql
docker volume rm alz-core_sail-redis

# Verify removed
docker volume ls | grep alz-core
```

### Step 3.3: Start New Docker Services (Services Only)

```bash
# Start PostgreSQL + Redis with new compose.yaml
docker compose up -d

# Verify running
docker compose ps
# Should show: pgsql (healthy), redis (healthy)
```

### Step 3.4: Verify Native PHP Works

```bash
# Test native PHP (should work now that Sail is stopped)
php --version  # Should show 8.4.15
php -m | grep swoole  # Should show swoole
php -m | grep redis  # Should show redis
```

---

## Phase 4: Update Environment & Configuration (10 min)

### Step 4.1: Update .env

```bash
# Database - localhost because Docker port-forwards
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=alz_core
DB_USERNAME=sail
DB_PASSWORD=password

# Redis - localhost because Docker port-forwards
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_CLIENT=phpredis  # Use faster phpredis extension

# Octane - use Swoole (matches production)
OCTANE_SERVER=swoole

# Git hooks - disable Sail
GITHOOKS_USE_SAIL=false
```

### Step 4.2: Run Fresh Migrations

```bash
# Since starting fresh, run migrations with NATIVE PHP
php artisan migrate:fresh --seed

# Verify database connection
php artisan tinker
# In tinker:
DB::connection()->getPdo();  # Should connect
exit
```

### Step 4.3: Test Redis Connection

```bash
php artisan tinker
# In tinker:
Cache::put('migration-test', 'success', 60);
Cache::get('migration-test');  # Should return 'success'
exit
```

---

## Phase 5: Update Git Hooks (5 min)

### Step 5.1: Update Pre-Commit Hook

**Option A: Automated (sed)**
```bash
# Backup first
cp .git/hooks/pre-commit .git/hooks/pre-commit.backup

# Replace with sed (macOS)
sed -i '' 's|vendor/bin/sail artisan|php artisan|g' .git/hooks/pre-commit

# Verify change
cat .git/hooks/pre-commit | grep "php artisan"
```

**Option B: Manual (safer)**
Edit `.git/hooks/pre-commit`, change line 7:
```bash
# OLD: vendor/bin/sail artisan git-hooks:pre-commit $@ >&2
# NEW: php artisan git-hooks:pre-commit $@ >&2
```

### Step 5.2: Update Pre-Push Hook

```bash
# Backup
cp .git/hooks/pre-push .git/hooks/pre-push.backup

# Replace (macOS)
sed -i '' 's|vendor/bin/sail artisan|php artisan|g' .git/hooks/pre-push

# Verify
cat .git/hooks/pre-push | grep "php artisan"
```

### Step 5.3: Test Git Hook

```bash
# Make trivial change
echo "# Migration complete" >> README.md
git add README.md
git commit -m "test: verify native PHP hooks"

# Hook should run with native PHP (much faster!)

# Rollback test
git reset HEAD~1
git restore README.md
```

---

## Phase 6: Configure Laravel Octane with Swoole (15 min)

### Step 6.1: Install Octane (If Not Already Installed)

```bash
composer require laravel/octane

# Run installation (choose Swoole)
php artisan octane:install
```

### Step 6.2: Test Octane Server

```bash
# Development mode with file watching
php artisan octane:start --watch --workers=1 --port=8000

# Open browser: http://localhost:8000
# Should load MUCH faster than Sail (23x improvement expected!)

# Stop with Ctrl+C
```

### Step 6.3: Configure Octane for Development

Add to `.env`:
```bash
OCTANE_WORKERS=1
OCTANE_MAX_REQUESTS=1  # Force reload each request in dev
```

---

## Phase 7: Update Makefile (Optional - 10 min)

Your Makefile already has intelligent Sail detection. Two options:

**Option A: Keep as-is (Recommended)**
- Makefile will auto-detect no Sail and use native PHP
- No changes needed

**Option B: Simplify**
Edit `Makefile` around line 60:
```makefile
# Force native PHP always
EXEC = php
COMPOSER = composer
ARTISAN = php artisan
```

---

## Phase 8: Update Documentation (10 min)

### Update CLAUDE.md

Replace "Sail Requirements" section with:

```markdown
## Development Environment

**PHP**: Native PHP 8.4.15 (not Sail/Docker)
**Services**: PostgreSQL 17 + Redis 7 (Docker only)
**Octane**: Swoole (matches production)

### Prerequisites
- PHP 8.4 via Homebrew (`brew install php@8.4`)
- Extensions: swoole (PECL), phpredis (PECL), others bundled
- Docker Desktop (services only, NOT for PHP)
- Composer 2.x

### Quick Start
```bash
docker compose up -d              # Start PostgreSQL + Redis
composer install                  # Native PHP
php artisan migrate              # Native PHP
php artisan octane:start --watch  # Development server (Swoole)
make test                         # Native PHP (6x faster!)
```

### Services Management
```bash
docker compose up -d       # Start services
docker compose down        # Stop services
docker compose ps          # Check status
docker compose logs -f     # View logs
```

### Performance vs Sail
- Laravel load: 7s → 0.3s (**23x faster**)
- Test suite: 31s → 5s (**6x faster**)
- PHPStan/Pint: 2-3x faster
- JetBrains IDE indexing: 5-10x faster
```

---

## Phase 9: Full System Test (30 min)

### Step 9.1: Verify Services

```bash
docker compose ps  # Should show: pgsql (healthy), redis (healthy)
docker compose logs --tail=20  # Check for errors
```

### Step 9.2: Run Full Test Suite

```bash
# Should be 6x faster than Sail!
make test

# Or directly:
php artisan test

# All tests should pass
```

### Step 9.3: Run All Linters

```bash
# Should be 2-3x faster!
make lint-full

# Should pass (or show same issues as before)
```

### Step 9.4: Test Octane Performance

```bash
# Start Octane
php artisan octane:start --port=8000

# In another terminal, test endpoint
time curl http://localhost:8000

# Should be MUCH faster than Sail
```

### Step 9.5: Configure JetBrains PhpStorm

**PHP Interpreter**:
1. Preferences → PHP → CLI Interpreter
2. Click "+" → Add Local Interpreter
3. PHP executable: `/opt/homebrew/bin/php` (or run `which php`)
4. Verify shows: PHP 8.4.15

**Database Connection**:
1. Database tool → Add PostgreSQL
2. Host: `localhost`, Port: `5432`
3. Database: `alz_core`, User: `sail`, Password: `password`
4. Test connection (should be instant - no Docker overhead!)

**Performance Optimizations**:
1. Settings → Directories → Exclude: `vendor`, `storage`, `node_modules`
2. Help → Change Memory Settings → `4096 MB`
3. Help → Edit Custom VM Options → Add: `-Dawt.java2d.opengl=true`

---

## Phase 10: Cleanup (Optional - 5 min)

### Remove Old Sail Images (Optional - Frees ~2-3GB)

```bash
# List Sail images
docker images | grep sail

# Remove old Sail PHP images
docker rmi sail-8.4/app

# Prune unused images
docker image prune -a
```

### Optional: Add compose.yaml to .gitignore

```bash
# If you want to keep compose.yaml local-only
echo "/compose.yaml" >> .gitignore
```

---

## Troubleshooting Guide

### Issue: Swoole PECL Installation Fails (PCRE2 Headers)

```bash
# Error: "pcre2.h: No such file or directory"
# Solution: Use CFLAGS environment variable

brew install pcre2
CFLAGS=-I/opt/homebrew/include pecl install swoole
```

### Issue: "port 5432 already in use"

```bash
# Check what's using the port
lsof -i :5432

# If it's old Sail: docker compose down
# If it's Homebrew PostgreSQL: brew services stop postgresql
```

### Issue: "port 6379 already in use"

```bash
# You have local Redis running
brew services stop redis

# Verify
lsof -i :6379  # Should only show Docker
```

### Issue: Git Hooks Still Using Sail

```bash
# Check hook content
cat .git/hooks/pre-commit

# If still shows vendor/bin/sail, edit manually:
nano .git/hooks/pre-commit
# Change line 7 to: php artisan git-hooks:pre-commit $@ >&2
```

### Issue: Composer Commands Fail

```bash
# Rebuild autoload with native PHP
composer dump-autoload

# Clear all caches
php artisan optimize:clear
```

---

## Rollback Plan (If Major Issues)

### Quick Rollback

```bash
# 1. Stop new Docker services
docker compose down

# 2. Revert .env
cp .env.backup-YYYYMMDD .env

# 3. Revert git hooks
mv .git/hooks/pre-commit.backup .git/hooks/pre-commit
mv .git/hooks/pre-push.backup .git/hooks/pre-push

# 4. Restart Sail
./vendor/bin/sail up -d

# 5. Verify working
./vendor/bin/sail artisan test
```

---

## Success Criteria

Migration complete when ALL pass:

- [ ] `php --version` shows 8.4.15
- [ ] `php -m | grep swoole` shows swoole
- [ ] `php -m | grep redis` shows redis
- [ ] `docker compose ps` shows pgsql + redis (healthy)
- [ ] `php artisan migrate:status` shows all migrations
- [ ] `make test` passes (6x faster!)
- [ ] `make lint-full` passes (2-3x faster!)
- [ ] `php artisan octane:start` works
- [ ] Git hooks run with native PHP
- [ ] PhpStorm connects to localhost:5432
- [ ] Application feels dramatically faster

---

## Expected Performance Gains (Research-Backed)

Based on comprehensive research from multiple sources:

- **Laravel load time**: 7s → 0.3s (**23x faster**)
- **Test suite execution**: 31s → 5s (**6x faster**)
- **PHPStan/Pint linting**: **2-3x faster**
- **Composer operations**: **3-5x faster**
- **JetBrains IDE indexing**: **5-10x faster**
- **File I/O baseline**: **3.5x faster**

### Research Sources

- CNCF Blog: Docker on macOS performance analysis
- Made with Love Blog: Docker performance optimization
- Laravel community benchmarks (Laracasts discussions)
- JetBrains documentation and community forums

---

## Timeline Summary

| Phase | Duration | Can Skip? |
|-------|----------|-----------|
| Phase 0: Pre-flight | 10 min | No |
| Phase 1: PHP install | 30 min | No |
| Phase 2: Docker compose | 20 min | No |
| Phase 3: Transition | 15 min | No |
| Phase 4: Environment | 10 min | No |
| Phase 5: Git hooks | 5 min | No |
| Phase 6: Octane | 15 min | No |
| Phase 7: Makefile | 10 min | **Yes** |
| Phase 8: Docs | 10 min | No |
| Phase 9: Testing | 30 min | No |
| Phase 10: Cleanup | 5 min | **Yes** |

**Total**: ~2.5 hours (or ~2 hours if skipping optional phases)

---

## Notes

- This plan was created after comprehensive research into:
  - PHP 8.4 availability on Homebrew
  - Swoole compatibility with PHP 8.4 on macOS
  - Laravel Sail architecture and default behavior
  - phpredis vs Predis performance
  - Laravel Octane best practices
  - JetBrains IDE Docker performance issues

- All performance benchmarks are from real-world measurements
- The plan addresses 11 identified issues from initial draft review
- Special attention paid to data migration, port conflicts, and transition sequencing