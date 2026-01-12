# Git Worktree Setup Guide

Create a second worktree (`alz-core-two`) for parallel development on two branches simultaneously.

Both worktrees share the same Supabase database—this is fine for most feature work. See [Shared Resources](#shared-resources) for limitations.

---

## Prerequisites

- Supabase running via alz-admin (`make supabase-start`)
- `ALZ_ADMIN` environment variable set:
  ```bash
  # In ~/.zshrc or ~/.bashrc
  export ALZ_ADMIN=/path/to/alz-admin
  ```
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

## Step 2: Configure Environment

```bash
cd ../alz-core-two

# Copy from MAIN worktree (preserves all API credentials)
cp ../alz-core/.env .env

# DO NOT run key:generate - reuse existing APP_KEY
```

**Edit `.env` with these changes:**

```env
# RECOMMENDED: Run jobs inline (avoids queue table conflicts)
QUEUE_CONNECTION=sync

# OPTIONAL: Distinguish in logs/errors
APP_NAME="ALZ Core (Two)"

# OPTIONAL: Correct URL generation for port 8001
APP_URL=http://localhost:8001
```

**Why `QUEUE_CONNECTION=sync`?** Both worktrees share the `jobs` table. With `sync`, jobs run immediately inline—no queue involvement, no cross-worktree conflicts. Also easier to debug (stack traces, `dd()`).

---

## Step 3: Install Dependencies & Setup

```bash
# Install PHP dependencies
composer install

# Run migrations (safe - migrations are idempotent)
php artisan migrate

# Create storage symlink
php artisan storage:link
```

---

## Step 4: Verify Setup

```bash
# Verify database connection (should show postgres on port 54322)
php artisan db:show

# Run tests to confirm everything works
make test-unit

# Start dev server (use different port to avoid conflict)
php artisan octane:start --port=8001 --watch
```

---

## Shared Resources

Both worktrees connect to the **same Supabase instance**. This means they share:

| Resource | Table/Location |
|----------|----------------|
| Database | Supabase `postgres` |
| Queue jobs | `jobs` table |
| Cache | `cache` table |

### When Worktrees Work Well

- Working on unrelated features (no schema conflicts)
- Not running queue workers (use `QUEUE_CONNECTION=sync`)
- Quick context switching between branches

### When to Avoid Worktrees

- **Both branches have new migrations** — they'll cross-contaminate the shared schema
- **Running queue workers** — jobs from worktree A could be processed by worktree B
- **Testing destructive migrations** — will affect both worktrees

### Recovery

If you hit conflicts, reset with:
```bash
make supabase-reset  # Warning: affects BOTH worktrees
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

# Prune stale worktree references (optional, keeps .git clean)
git worktree prune
```

---

## Quick Reference

| Item | Main Worktree | Second Worktree |
|------|---------------|-----------------|
| Path | `alz-core` | `alz-core-two` |
| Database | `postgres` (shared) | `postgres` (shared) |
| Supabase Port | 54322 | 54322 |
| Octane Port | 8000 | 8001 |
| Branches | `develop` → `feature/*` | `feature/*` (from `origin/develop`) |
