# Database Guidelines

## CRITICAL: Shared Database with Supabase

This Laravel project shares PostgreSQL with Supabase (`alz-admin`). Supabase owns `auth.*` tables.

### Forbidden Commands

**NEVER run** - destroys Supabase auth tables:
- `php artisan migrate:fresh`
- `php artisan migrate:refresh`
- `php artisan migrate:reset`
- `php artisan db:wipe`

Blocked via `.claude/settings.json`.

### Full Database Reset

Use coordinated reset instead:

```bash
make db-reset-full
```

Steps: (1) Reset Supabase auth + seed test users, (2) Run Laravel migrations

## Octane Safety

**Never use `static` variables in connection callbacks** - they persist across Octane requests, causing security issues. Use Laravel Context or just run the operation every time.

## Connections

- `pgsql` - Migrations/seeders (no RLS)
- `pgsql_rls` - Default, user-scoped queries
- `pgsql_admin` - Admin ops, clears stale claims

Connection name determines callback behavior, not config.

## Multi-Schema Tables

Eloquent models need explicit schema: `protected $table = 'access.roles';`

## Migration Naming Convention

**CRITICAL: Include schema name in migration filenames.**

Format: `{timestamp}_{action}_{schema}_{table}.php`

Examples:
- ✅ `2026_01_12_124157_add_status_sort_order_to_shopwired_orders.php`
- ✅ `2026_01_13_010000_create_shopwired_order_refunds_table.php`
- ❌ `2026_01_12_124157_add_status_sort_order_to_orders_table.php`

**Why**: Schema resets use `DROP SCHEMA CASCADE` + clear migration records by pattern matching (`%schema_name%`). Migrations without schema in filename get skipped on re-run, causing missing column errors.