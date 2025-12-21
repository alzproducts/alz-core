# Git Worktree Setup Guide

Create a second worktree (`alz-core-two`) for parallel development with:
- Isolated PostgreSQL database
- Same `develop` → `feature/*` workflow
- Shared Docker containers (PostgreSQL, Redis)

---

## Prerequisites

- Docker running with `alz-core-pgsql-1` container
- Branch name for worktree (new or existing)

> **Important:** A branch can only be checked out in ONE worktree at a time. You can't have `develop` checked out in both worktrees, but you can create feature branches from `origin/develop` in either.

---

## Step 1: Create the Worktree

```bash
# From the main alz-core directory

# Create worktree with initial feature branch from origin/develop
git fetch origin
git worktree add ../alz-core-two -b feature/initial-branch origin/develop
```

**Switching branches in the second worktree:**
```bash
cd ../alz-core-two

# Create new feature branches from origin/develop
git fetch origin
git checkout -b feature/another-feature origin/develop
```

> **Note:** Both worktrees create feature branches from `origin/develop`. You don't need `develop` checked out locally in the second worktree.

---

## Step 2: Create Separate Database

```bash
# Create new database for the second worktree
docker exec -it alz-core-pgsql-1 createdb -U sail alz_core_two

# Verify it was created
docker exec -it alz-core-pgsql-1 psql -U sail -c "\l" | grep alz_core
```

---

## Step 3: Configure Worktree Environment

```bash
cd ../alz-core-two

# Copy from MAIN worktree (preserves all API credentials)
cp ../alz-core/.env .env

# DO NOT run key:generate - reuse existing APP_KEY
```

**Edit `.env` — only these 2-3 changes needed:**

```env
# 1. REQUIRED: Change database name
DB_DATABASE=alz_core_two

# 2. OPTIONAL: Separate Redis DB (only if using Redis for cache/queue)
# Default config uses PostgreSQL, so this may not apply
REDIS_DB=1

# 3. OPTIONAL: Different app name for clarity in logs/errors
APP_NAME="ALZ Core (Two)"
```

> **Note:** DB_HOST should already be `127.0.0.1` in your main .env (native PHP setup).

---

## Step 4: Install Dependencies & Setup

```bash
# Install PHP dependencies
composer install

# Run migrations on new database
php artisan migrate

# Create storage symlink (if using public storage)
php artisan storage:link

# Optional: Seed data if needed
php artisan db:seed
```

---

## Step 5: Verify Setup

```bash
# Verify database connection
php artisan db:show

# Run tests to confirm everything works
make test-unit

# Start dev server (use different port to avoid conflict)
php artisan octane:start --port=8001 --watch
```

---

## Optional: PhpStorm/JetBrains Setup

Open the worktree as a separate project:
- File → Open → Select `../alz-core-two`
- Configure PHP interpreter if needed

---

## Cleanup (When Done)

```bash
# From main worktree

# Remove worktree (must commit/stash changes first, or use --force)
git worktree remove ../alz-core-two

# Drop the database
docker exec -it alz-core-pgsql-1 dropdb -U sail alz_core_two

# Prune stale worktree references (optional, keeps .git clean)
git worktree prune
```

---

## Quick Reference

| Item | Main Worktree | Second Worktree |
|------|---------------|-----------------|
| Path | `alz-core` | `alz-core-two` |
| Database | `alz_core` | `alz_core_two` |
| Redis DB | `0` (default) | `1` (optional) |
| Port | 8000 | 8001 |
| Docker | Shared containers | Shared containers |
| Branches | `develop` → `feature/*` | `feature/*` (from `origin/develop`) |
