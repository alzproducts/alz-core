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

This runs a preflight and two steps in sequence:

**Preflight — `php artisan dev:seed-fake --dry-run`**: checks the seed guard and generates the slice with no writes, so a guard failure stops the reset before anything is wiped.

**Step 1 — `make supabase-reset`** (runs `pnpm db:setup-local` in `${FRONTEND_APP}`):
- Recreates the PostgreSQL database from scratch
- Applies all Supabase schema migrations (`supabase/migrations/`)
- Generates TypeScript types
- Seeds test users

**Step 2 — `php artisan dev:seed-fake`**:
- Applies all Laravel migrations (business tables, indexes, schemas)
- Seeds the synthetic, production-shaped products slice (no sync jobs, no external calls)

> Both steps are required. `pnpm db:setup-local` only restores the base Supabase schema; step 2 creates the business tables (linnworks, shopwired, etc.) and fills the products slice.

### What Gets Wiped

`make db-reset-full` destroys **all local data**: the seeded products slice, any synced Linnworks/ShopWired data, orders, purchase orders, suppliers — everything — and re-seeds the synthetic slice.

**Information only — the reset also covers alz-admin (`${FRONTEND_APP}`).** Running `make db-reset-full` is fine; this note just explains its full scope. Both projects use the same local Supabase project, and step 1 runs alz-admin's `supabase db reset`, so alz-admin's local auth users and data are reset as well. Afterwards, only these are re-seeded:
- alz-admin's `database.types.ts` (regenerated)
- alz-admin's test users (`seed-test-users`)
- alz-core's synthetic products slice (`dev:seed-fake`)

**Do NOT use for incremental schema changes.** For a column change on a feature branch table, use a direct SQL statement or rollback just that migration:

```bash
# Add/drop a column directly (safe, fast, no data loss)
php artisan db:execute "ALTER TABLE linnworks.purchase_order_items ALTER COLUMN bin_rack DROP NOT NULL"

# Or rollback only the affected migration(s)
php artisan migrate:rollback --step=1
php artisan migrate
```

## Supabase Baseline (ADR-0016)

Every database alz-core migrates runs Supabase's images, with migrations run as database `postgres`, role `postgres` (not a superuser, as in production):

| Environment | Database source |
|-------------|-----------------|
| Local dev | Supabase CLI stack in `${FRONTEND_APP}` (images from its re-linked `supabase/.temp/`) |
| CI `tests` job | `database/baseline/compose.yaml`, project `baseline` |
| Verify skill | `database/baseline/compose.yaml`, one private project per run |
| Dev image (`Dockerfile.dev`) | the baseline images, migrated at build time |
| `docker-compose.prod.yml` | `include` of `database/baseline/compose.yaml` |

- Caller contract: `up -d --wait pgsql` → `run --rm auth-migrate` → `php artisan migrate --force`.
- DO NOT create Supabase-owned objects (`auth` schema or objects, `anon`/`authenticated`/`service_role`/`supabase_*` roles) in a migration; a missing one must fail the migration. `SupabaseLookAlikeTest` enforces this.
- `database/baseline/versions.env` is the only file naming the image versions; `SupabaseImagePinTest` enforces this.

**Bumping the pin** (after the hosted project upgrades):
1. `bin/supabase-hosted-version <${FRONTEND_APP} root>` prints the hosted Postgres version → `SUPABASE_POSTGRES_VERSION`.
2. Re-link in `${FRONTEND_APP}` (`supabase link`); `supabase/.temp/gotrue-version` → `SUPABASE_AUTH_VERSION`.
3. Edit `versions.env`; CI rebuilds the dev image from it.

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