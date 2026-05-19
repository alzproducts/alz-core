# Database Guidelines

## CRITICAL: Shared Database with Supabase

This Laravel project shares PostgreSQL with Supabase (`${FRONTEND_APP}`). Supabase owns `auth.*` tables.

### Forbidden Commands

**NEVER run** - destroys Supabase auth tables:
- `php artisan migrate:fresh`
- `php artisan migrate:refresh`
- `php artisan migrate:reset`
- `php artisan db:wipe`

Blocked via `.claude/settings.json`.

### Full Database Reset

**Always use `make db-reset-full`** — never run the two steps in the wrong order or separately without understanding what each does.

```bash
make db-reset-full
```

This runs two steps in sequence:

**Step 1 — `make supabase-reset`** (runs `pnpm db:setup-local` in `${FRONTEND_APP}`):
- Recreates the PostgreSQL database from scratch
- Applies all Supabase schema migrations (`supabase/migrations/`)
- Generates TypeScript types
- Seeds test users

**Step 2 — `make migrate`**:
- Applies all Laravel migrations (business tables, indexes, schemas)

> Both steps are required. `pnpm db:setup-local` only restores the base Supabase schema. Without `make migrate`, all business tables (linnworks, shopwired, etc.) will be missing.

### What Gets Wiped

`make db-reset-full` destroys **all locally synced data**: Linnworks stock items, orders, purchase orders, ShopWired products/orders, suppliers — everything. All data must be re-synced afterwards.

**Do NOT use for incremental schema changes.** For a column change on a feature branch table, use a direct SQL statement or rollback just that migration:

```bash
# Add/drop a column directly (safe, fast, no data loss)
php artisan db:execute "ALTER TABLE linnworks.purchase_order_items ALTER COLUMN bin_rack DROP NOT NULL"

# Or rollback only the affected migration(s)
php artisan migrate:rollback --step=1
php artisan migrate
```

## Octane Safety

**Never use `static` variables in connection callbacks** - they persist across Octane requests, causing security issues. Use Laravel Context or just run the operation every time.

## Connections

- `pgsql` - Migrations/seeders (no RLS)
- `pgsql_rls` - Default, user-scoped queries
- `pgsql_admin` - Admin ops, clears stale claims

Connection name determines callback behavior, not config.

## Multi-Schema Tables

> Eloquent `protected $table` schema-qualification rules → `.claude/rules/eloquent-write-models.md` (auto-loads on `*Model.php`)

## Migration Naming Convention

> Migration filename + schema-qualification rules → `.claude/rules/migrations.md` (auto-loads on `database/migrations/**/*.php`)

## Order Deduplication

When orders are "edited" in ShopWired, a new order is created with the same `reference` but different `external_id`. The original is cancelled.

**For queries needing one order per reference:**
- Use `shopwired.orders_deduplicated` view (preferred)
- This view applies `DISTINCT ON (reference)` with proper ordering

**For audit/history queries needing all orders:**
- Use `shopwired.orders` table directly