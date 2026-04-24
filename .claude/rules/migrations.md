---
paths:
  - "database/migrations/**/*.php"
---

# Migration Rules

## Filename Convention

**CRITICAL: Include schema name in migration filenames.**

Format: `{timestamp}_{action}_{schema}_{table}.php`

Examples:
- ✅ `2026_01_12_124157_add_status_sort_order_to_shopwired_orders.php`
- ✅ `2026_01_13_010000_create_shopwired_order_refunds_table.php`
- ❌ `2026_01_12_124157_add_status_sort_order_to_orders_table.php` (no schema)

**Why**: Schema resets use `DROP SCHEMA CASCADE` + clear migration records by pattern matching (`%schema_name%`). Migrations without schema in the filename get skipped on re-run, causing missing-column errors.

## Schema-Qualified Table References

Always qualify table names with their schema inside migrations:

```php
Schema::create('shopwired.orders', function (Blueprint $table) { /* … */ });
Schema::table('linnworks.stock_items', function (Blueprint $table) { /* … */ });
```

Never rely on the default `public` schema.

## Postgres Identifier Truncation Pitfall

Postgres truncates identifiers at 63 characters. If `->unique()` + `->foreign()` on long table+column combinations both truncate to the same 63-char name, the migration fails with `SQLSTATE 42710` (duplicate object). Transaction rollback erases the evidence — check migration logs carefully and shorten names explicitly when nearing the limit.
