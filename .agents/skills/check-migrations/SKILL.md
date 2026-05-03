---
name: check-migrations
description: Pre-commit review of database migrations for correctness, safety, readability, and project conventions. Catches PostgreSQL issues, data safety problems, shared-DB concerns, view consistency, and performance gaps.
allowed-tools: Bash(git *), Bash(php artisan tinker *), Bash(make *), mcp__sequential-thinking__sequentialthinking, mcp__intellij__*, mcp__phpstorm__*, Read, Grep, Glob, Edit, Write, AskUserQuestion
model: opus
effort: xhigh
---

# check-migrations

Pre-commit migration review skill. Validates database migrations against six categories of potential issues before they are committed.

## Input Resolution

1. If `$ARGUMENTS` contains a file path or filename, review that specific migration
2. If `$ARGUMENTS` contains `--fast`, skip EXPLAIN validation (static analysis only)
3. If no path in arguments, auto-detect: find uncommitted migration files via `git status` and `git diff --name-only` in `database/migrations/`
4. If no uncommitted migrations found, report "No migrations to review" and exit

## Review Target
<review_target>
#$ARGUMENTS
</review_target>

## Critical Rule: User Decisions via AskUserQuestion

**You MUST use the AskUserQuestion tool for ANY decision that requires user input.** Do NOT output questions or choices as plain text. Every question, clarification, or choice MUST go through AskUserQuestion.

## Review Process

### Phase 1: Gather Context

Use `mcp__sequential-thinking__sequentialthinking` to begin structured analysis.

1. Read each migration file to review
2. Identify the migration type: table creation, table alteration, view creation/recreation, schema creation, data migration, index/constraint, function/trigger
3. For view recreations: find the most recent prior migration that defined the same view (search `database/migrations/` for the view name)
4. Identify the target schema from table/view names in the SQL

### Phase 2: Static Analysis — Six Categories

Run all checks against each migration file. Flag findings by severity.

#### Category 1: Structural & Convention Compliance

- [ ] Schema name present in filename (e.g., `_catalog_`, `_shopwired_`, `_linnworks_`)
  - EXCEPTION: framework/package migrations (`cache`, `jobs`, `telescope`, `horizon`) live in `public` without schema prefix
- [ ] All table/view references are schema-qualified (e.g., `catalog.products_view`, not just `products_view`)
- [ ] Schema creation uses idempotent guard (`SELECT EXISTS FROM pg_namespace` before `CREATE SCHEMA`)
- [ ] `down()` method exists and is reversible
- [ ] `declare(strict_types=1)` present
- [ ] Anonymous class migration format (not named class)

#### Category 2: PostgreSQL Correctness

- [ ] Identifier length: all table names, column names, index names, and constraint names are under 63 characters
  - Pay special attention to auto-generated names from `->unique()` and `->foreign()` on long table+column combos
  - Flag any name that exceeds 50 characters as MEDIUM (approaching limit)
- [ ] `DROP VIEW IF EXISTS` before `CREATE VIEW` (views cannot be ALTERed in PostgreSQL)
- [ ] Valid SQL syntax in heredocs (look for common mistakes: trailing commas before FROM, unclosed parentheses, missing JOIN conditions)
- [ ] `IF EXISTS` / `IF NOT EXISTS` guards where appropriate
- [ ] No reserved word collisions in column/table names without quoting

#### Category 3: Data Safety

- [ ] Column drops: flag as HIGH — verify intentional, check no other views/indexes depend on the column
- [ ] Type changes without explicit CAST: flag as CRITICAL — data loss risk
- [ ] NOT NULL additions on populated tables without DEFAULT: flag as CRITICAL — will fail if rows exist
- [ ] `DROP TABLE` without `IF EXISTS`: flag as MEDIUM
- [ ] Large table alterations that take ACCESS EXCLUSIVE locks: flag as HIGH with note about downtime risk
- [ ] Data backfills in same transaction as DDL: note lock duration concern

#### Category 4: Shared-DB / Supabase Awareness

- [ ] No touching `auth.*` tables (owned by Supabase)
- [ ] No `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` patterns
- [ ] RLS consideration: new tables will have RLS enabled automatically. Check if the migration needs explicit policies or if "no policy = block anon/authenticated" is intentional
- [ ] Permission grants: determine if the target schema has `ALTER DEFAULT PRIVILEGES` set
  - Read the schema creation migration for the target schema
  - If default privileges exist: no per-object GRANT needed (note this in findings as informational)
  - If no default privileges: flag missing GRANT statements as HIGH

#### Category 5: View-Specific Checks

- [ ] For view recreations: compare column list against the prior migration's version
  - Flag any columns that disappeared (CRITICAL)
  - Flag any columns that changed type or expression (HIGH)
  - Flag new columns added (informational — verify intentional)
- [ ] Paired view consistency: if migration modifies `catalog.products_view`, check if `catalog.product_variations_view` also needs updating (and vice versa). Flag if only one is updated and they share logic (pricing CTEs, supplier JOINs, etc.)
- [ ] `down()` for view recreations: verify the restored SQL matches the up() from the prior migration that defined this view
  - Read the prior migration's up() and compare against this migration's down()
  - Flag mismatches as HIGH (indicates the rollback would produce wrong schema)

#### Category 6: Performance

- [ ] New tables with foreign key columns: verify indexes exist on FK columns
- [ ] New JSONB columns: consider GIN index if queried with `@>`, `?`, `->>`
- [ ] Views with multiple JOINs: check that join keys have indexes on the underlying tables
- [ ] Full table scans: flag queries without WHERE clauses on large tables as MEDIUM
- [ ] Missing indexes on columns used in WHERE/ORDER BY within view definitions

### Phase 3: Readability Review (New Migrations Only)

Apply these standards to the migration(s) being reviewed. Do NOT compare against historical migration style.

#### Named CTEs for Reused Values
- Constants (tax rates, thresholds) must be defined in a CTE at the top, not as magic numbers inline
- Flag any numeric literal in a CASE/WHERE that represents a business rule

#### CTE Purpose Comments
- Each CTE must have a one-line `--` comment explaining what it computes
- Flag CTEs without comments as MEDIUM

#### Forward-Reference Ordering
- Tables/objects created first, then objects that reference them
- A migration must not reference an object before creating it within the same file
- Flag out-of-order as HIGH (can cause runtime failure)

#### Descriptive Aliases (3+ JOINs)
- Queries with 3 or more JOINs must use descriptive aliases (e.g., `products`, `variations`, `stock_items`)
- Single-letter aliases (p, v, s, si) are only acceptable in 2-table JOINs
- Flag as MEDIUM in new migrations

#### Section Comments (3+ Statements)
- Migrations with 3 or more `DB::statement` calls must have brief section comments explaining logical groupings
- Example: `// 1. Drop dependent views`, `// 2. Recreate with new columns`, `// 3. Restore permissions`
- Flag as LOW

### Phase 4: EXPLAIN Validation (Unless --fast)

If `--fast` was NOT specified:

1. Check if the local PostgreSQL is reachable via `php artisan tinker --execute="DB::connection('pgsql')->getPdo(); echo 'OK';"`
2. If DB unavailable: flag as **HIGH** finding — "Database unavailable, EXPLAIN validation skipped. Run migrations locally before committing."
3. If DB available, for each CREATE VIEW statement:
   - Extract the SELECT portion of the view definition
   - Run `EXPLAIN (FORMAT JSON)` via tinker to validate syntax and check for sequential scans on large tables
   - Wrap in a transaction and ROLLBACK — never persist anything
   - Flag syntax errors as CRITICAL
   - Flag sequential scans on tables with >10k expected rows as MEDIUM

### Phase 5: Triage & Resolve

Categorise each finding:

**Auto-fix** (apply immediately without asking):
- Missing `IF EXISTS` on DROP statements
- Missing schema prefix in filename (rename file)
- Missing `declare(strict_types=1)`
- Trailing commas in SQL
- Missing section comments (add them)

**Ask first** (batch into single AskUserQuestion):
- Any change to SQL logic
- Adding/removing columns from view definitions
- Permission/grant additions
- Anything touching data safety
- Anything you're less than 90% confident about

After triage:
1. Apply all auto-fixes using Edit
2. Ask about remaining issues in a single AskUserQuestion (group by severity)
3. Apply any fixes the user approves

### Phase 6: Summary Report

Format as severity-grouped findings:

```
## Migration Review: {filename}

### CRITICAL (must fix)
- [{file}:{line}] {description} — {why it matters}

### HIGH (should fix)
- [{file}:{line}] {description} — {why it matters}

### MEDIUM (consider)
- [{file}:{line}] {description} — {why it matters}

### LOW (suggestions)
- [{file}:{line}] {description}

### FIXED (auto-applied)
- [{file}:{line}] {what was fixed}

### PASSED
- {list of categories with no issues found}
```

If no issues found across all categories, report: "Migration review passed — no issues found."
