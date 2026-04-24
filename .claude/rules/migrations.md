---
paths:
  - "database/migrations/**/*.php"
---

# Migration Rules

## Filename Convention

- DO include the schema name: `{timestamp}_{action}_{schema}_{table}.php`
- EXCEPTION: framework/package migrations (jobs, telescope, horizon, any vendor-published) live in `public` without schema prefixes — do not rewrite

**Why**: schema resets run `DROP SCHEMA CASCADE` and clear migration records by pattern-matching `%schema_name%`. Migrations without a schema in the filename are skipped on re-run, causing missing-column errors.

## Schema-Qualified Table References

- DO qualify every table name with its schema inside `Schema::create` / `Schema::table` (e.g. `'shopwired.orders'`)
- DO NOT rely on the default `public` schema

## `DB::` Facade Is Allowed Here

Migrations are exempt from the "no `DB::` facade" rule — they run outside `DatabaseGateway`. `DB::statement`, `DB::selectOne`, `Schema::*` are all correct inside `up()` / `down()`.

## Idempotent Schema Creation

- DO guard `CREATE SCHEMA` by checking `pg_namespace` first — schema migrations re-run on `make db-reset-full`
- DO revoke permissions before `DROP SCHEMA CASCADE` in `down()`

## Postgres Identifier Truncation

Postgres truncates identifiers at 63 characters. If `->unique()` + `->foreign()` on long table+column combinations both truncate to the same name, the migration fails with `SQLSTATE 42710` (duplicate object) and the transaction rollback erases the evidence. Shorten names explicitly when nearing the limit.
