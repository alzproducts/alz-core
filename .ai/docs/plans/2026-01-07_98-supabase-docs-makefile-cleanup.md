# Plan: Fix Supabase Documentation and Makefile Commands

## Summary

Clarify the two-database paradigm (Supabase for local dev, Docker PostgreSQL for CI) by:
1. Adding new `supabase-reset` and `supabase-seed-users` commands for local development
2. Deleting dead code (`db-setup`, `db-reset`, `fresh` - nobody uses them)
3. Updating CI workflow to use Make commands for consistency
4. Updating documentation (README.md, CLAUDE.md)

---

## Impact Analysis

**Commands being deleted (dead code):**
| Command | Reason for Deletion |
|---------|---------------------|
| `db-setup` | Creates DBs in Docker Compose PostgreSQL - CI uses GitHub Actions services instead |
| `db-reset` | `migrate:fresh` without guard - dangerous, unused |
| `fresh` | `migrate:fresh --seed` - Laravel seeders are empty anyway |

**References to update:**
- `CLAUDE.md:117` - references `make db-setup` → remove/update
- `README.md:102` - references `make db-reset` → remove/update

**CI workflow (`.github/workflows/ci.yml`):** Update to use Make commands for consistency.

---

## Files to Modify

| File | Changes |
|------|---------|
| `Makefile` | Delete 3 commands, add 2 new supabase commands, update .PHONY |
| `.github/workflows/ci.yml` | Use `make migrate` instead of `php artisan migrate --force` |
| `README.md` | Add "Two Database Modes" section, update command references |
| `CLAUDE.md` | Update Quick Reference section |

---

## Phase 1: Makefile Changes

### 1.1 Delete Dead Code Commands

**Delete these targets entirely:**

```makefile
# DELETE: db-setup (lines 336-340)
db-setup: ## Create databases (main + testing) in Docker PostgreSQL
	...

# DELETE: fresh (lines 346-348)
fresh: ## Fresh database with seeders
	...

# DELETE: db-reset (lines 350-352)
db-reset: ## Reset database (migrate:fresh without seed)
	...
```

### 1.2 Add New Supabase Commands

**Add after existing `supabase-status` target (~line 382):**

```makefile
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
	cd $(ALZ_ADMIN) && tsx scripts/seed-test-users.ts
	@echo "$(GREEN)Test users seeded.$(NC)"
```

### 1.3 Update .PHONY Declaration (line 1)

- **Remove:** `db-setup`, `db-reset`, `fresh`
- **Add:** `supabase-reset`, `supabase-seed-users`

### 1.4 Keep `migrate` Command (already exists, safe for both contexts)

```makefile
migrate: ## Run database migrations
	@echo "$(MODE)"
	$(EXEC) artisan migrate
```

---

## Phase 2: CI Workflow Changes

### 2.1 Update `.github/workflows/ci.yml` (line 224-225)

**Before:**
```yaml
- name: Run database migrations
  run: php artisan migrate --force
```

**After:**
```yaml
- name: Run database migrations
  run: make migrate
```

**Note:** The `migrate` target runs `$(EXEC) artisan migrate`. In CI mode (`CI=true`), `$(EXEC)` resolves to `php`, so this becomes `php artisan migrate`. The `--force` flag is only needed for production; CI's `APP_ENV=testing` doesn't require it.

---

## Phase 3: README.md Changes

### 3.1 Add "Two Database Modes" Section

**Add after "Daily Development" section (~line 97), before "Database Commands":**

```markdown
### Database Modes

This project uses **two different database setups** depending on context:

| Mode | Database | Port | Managed By | When Used |
|------|----------|------|------------|-----------|
| **Local Dev** | Supabase PostgreSQL | 54322 | alz-admin project | Daily development |
| **CI/Testing** | Docker PostgreSQL | 5432 | Docker Compose | GitHub Actions |

**Local development** connects to Supabase (shared with alz-admin frontend). User authentication, profiles, and roles are managed by Supabase Auth.

**CI/Testing** uses isolated Docker PostgreSQL with mocked auth schema. This allows tests to run without Supabase.
```

### 3.2 Update "Database Commands" Section (lines 99-104)

Replace current section with:

```markdown
**Database Commands** (Local Development):
```bash
make supabase-status      # Check if Supabase is running
make supabase-reset       # Full reset: wipe DB, seed data, create test users
make supabase-seed-users  # Seed test users only (no DB wipe)
make migrate              # Run Laravel migrations (safe, additive)
make supabase-stop        # Stop Supabase when done
```
```

### 3.3 Update First-Time Setup Section (lines 56-78)

Update to clarify Supabase setup:

```markdown
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
# (This runs in alz-admin: resets DB, regenerates types, seeds users)
make supabase-reset

# Start Redis
make redis

# Run Laravel migrations (adopts existing Supabase schema)
php artisan migrate

# Verify tests pass
make test
```
```

---

## Phase 4: CLAUDE.md Changes

### 4.1 Update Quick Reference Section (around line 117)

Replace the current quick reference with:

```markdown
### Quick Reference
```bash
docker compose up -d              # Start Redis (PostgreSQL is via Supabase)
make supabase-reset               # Full Supabase reset with test users
make supabase-seed-users          # Seed test users only
php artisan migrate               # Run migrations
php artisan octane:start --watch  # Dev server with hot reload
make test-unit                    # Run unit tests (~5s, no external deps)
make test                         # Run all tests (unit + integration)
make lint                         # Run linters
```
```

---

## Phase 5: Verification

After implementation:

1. **Test `make help`** - Verify new commands appear, deleted commands removed
2. **Test `supabase-reset`** - Should run full alz-admin setup (db reset, types, users)
3. **Test `supabase-seed-users`** - Should seed users without DB reset
4. **Test CI** - Push branch and verify `make migrate` works in GitHub Actions

---

## Test Users Reference

After `make supabase-reset`, these test users will exist:

| Email | Role | Password |
|-------|------|----------|
| `default-standard@alzadmin.test` | standard | `%vSm@yAZqae2eVvjl91F` |
| `default-guest@alzadmin.test` | guest | (same) |
| `default-admin@alzadmin.test` | admin | (same) |
| `default-manager@alzadmin.test` | manager | (same) |
| `tom@alzadmin.test` | manager | From `TEST_DEVELOPER_PASSWORD` |

Password sourced from `.env.test` in alz-admin (`TEST_PASSWORD_AUTO_SHARED`).
