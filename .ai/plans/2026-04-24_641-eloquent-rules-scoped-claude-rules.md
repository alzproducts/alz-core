# Plan: Migrate Eloquent Rules into Centralised `.claude/rules/`

## Context

The project's Eloquent conventions are currently spread across six CLAUDE.md files. Because CLAUDE.md loading is directory-hierarchical, these rules load even when Claude is touching files they don't apply to (e.g. the Infrastructure CLAUDE.md loads for every Infrastructure file, including API clients that have nothing to do with Eloquent). Claude Code supports filename-scoped rules via `.claude/rules/*.md` with a `paths:` frontmatter field — quote from the Anthropic *Advanced Patterns* PDF:

> Rules can also be scoped to specific file paths by providing a "paths" field in the frontmatter.

This plan moves Eloquent-specific, **file-editing-time** conventions into `.claude/rules/`, scoped so they only attach when Claude touches a matching file. **Operational/safety rules stay put** in CLAUDE.md (see *Scope Boundary* below) — they must load unconditionally, not only when a matching file is open.

## Scope Boundary — What Moves vs What Stays

**Moves to `.claude/rules/`** (conventions that apply when writing/editing a specific file type):
- How to create an Eloquent repository (interface + `AbstractEloquentRepository`)
- Domain↔Model mapping pattern (`Model::attributesFromDomain()`)
- Bulk insert timestamp quirk
- Eloquent model conventions (`EloquentDomainMappableInterface`, `AutoDomainMappingTrait`, `$guarded`, schema-qualified `$table`)
- Migration filename convention
- Repository interface `@throws` declarations for `DatabaseGateway`

**Stays in CLAUDE.md** (must be always-loaded):
- `database/CLAUDE.md` forbidden commands (`migrate:fresh`, `db:wipe` etc.) — safety-critical; must fire when Claude is about to *run* a command, not only when opening a file
- `database/CLAUDE.md` full-reset procedure, Octane safety, connection-name behaviour — operational
- Root `CLAUDE.md` "Database: Use DatabaseGateway, never DB:: facade" — broad architectural rule that can also fire for non-file-scoped contexts (e.g. tinker commands)
- `app/Infrastructure/CLAUDE.md` general catch-and-translate pattern — applies to API clients too, not just Eloquent repos
- `app/Infrastructure/Shopwired/CLAUDE.md` schema table list — reference documentation

## Known Uncertainty

The public docs (Substack post + Anthropic PDF) confirm the `paths` field exists and uses glob patterns, but don't document:
- Exact glob dialect (does `Eloquent*Repository.php` work without directory context, or is globstar needed?)
- Loading trigger (Read, Edit, Write, Glob, Grep?)
- Size / precedence / multi-match behaviour

**Mitigation**: Phase 1 of execution is a single-file pilot. We create `eloquent-repositories.md` first, open one matching file, and confirm the rule loads before creating the remaining files. If the glob form doesn't match as expected, iterate on the pattern (directory-rooted vs `**/` prefix) before committing.

## Target Rule Files

### 1. `.claude/rules/eloquent-repositories.md`

```yaml
paths:
  - "app/Infrastructure/**/Eloquent*Repository.php"
  - "app/Infrastructure/**/AbstractEloquentRepository.php"
```

**Content sources**:
- `app/Infrastructure/CLAUDE.md:3-7` — repository creation checklist
- `app/Infrastructure/CLAUDE.md:49-55` — `Model::attributesFromDomain()` mapping pattern
- `app/Infrastructure/CLAUDE.md:57-59` — bulk insert timestamp rule
- Root `CLAUDE.md:207` — `DatabaseGateway` vs `DB::` facade (mirror, since this specifically governs repo implementations)

**Matches** 21 existing files (e.g. `EloquentCustomFieldRepository.php`, `EloquentStockItemRepository.php`) plus `AbstractEloquentRepository.php`.

### 2a. `.claude/rules/eloquent-write-models.md`

```yaml
paths:
  - "app/Infrastructure/**/Models/*Model.php"
  - "!app/Infrastructure/**/Models/*ViewModel.php"
```

**Content sources**:
- `app/Infrastructure/Shopwired/Models/CLAUDE.md:1-36` — general model rules (`EloquentDomainMappableInterface`, `AutoDomainMappingTrait`, `$guarded`, schema-qualified `$table`)
- `database/CLAUDE.md:65-67` — multi-schema `protected $table = 'access.roles';` rule

**Leave in `Shopwired/Models/CLAUDE.md`**: the Shopwired-specific child-table two-column pattern, delete-all→insert-all sync strategy, and parent external-ID delete rule — these are integration-specific, not cross-cutting Eloquent rules.

**Matches** ~37 existing `*Model.php` files (excluding ViewModels).

**Negation caveat**: if the `paths:` glob dialect doesn't support `!` exclusion, the pilot (Phase 1 of execution) will catch this. Fallback: drop the negation and add a one-line disclaimer to the rule content: *"This rule applies to write-path models only. For read-only `*ViewModel.php` files, see `eloquent-view-models.md`."*

### 2b. `.claude/rules/eloquent-view-models.md`

```yaml
paths:
  - "app/Infrastructure/**/Models/*ViewModel.php"
```

**Content** (new, concise):
- ViewModels are read-only Eloquent models backed by PostgreSQL views (e.g. `catalog.products_view`)
- Do NOT implement `EloquentDomainMappableInterface` — they're read projections, not write targets
- Do NOT write to them (no `save()`, `update()`, `insert()`, `delete()`); use the underlying write model instead
- `protected $table = 'schema.thing_view';` — schema-qualified
- Useful docblock annotations: `/** @property ... */` for each view column
- Write operations for the underlying entity continue to use the paired write model (e.g. `ProductModel` for `ProductViewModel`)

**Content source**: synthesised from reading `ProductViewModel.php` conventions (read-only, view-backed, paired with a write `*Model.php`). This codifies an existing pattern — not a new rule.

**Matches** 5 files: `OrderViewModel.php`, `ProductViewModel.php`, `CustomerViewModel.php`, `ProductVariationViewModel.php` (and counterparts under `Customer/Models/`, `Catalog/Order/Models/`).

### 3. `.claude/rules/migrations.md`

```yaml
paths:
  - "database/migrations/**/*.php"
```

**Content sources**:
- `database/CLAUDE.md:69-80` — schema-prefixed filename convention and the reason (DROP SCHEMA CASCADE + pattern matching)

(Supabase migrations live in the separate `alz-admin` repo — not in this project — so no Supabase path needed.)

**Does NOT include**: the forbidden-command list, `make db-reset-full` procedure, or connection-name behaviour — those stay in `database/CLAUDE.md` because they're operational / safety-critical.

### 4. `.claude/rules/repository-contracts.md`

```yaml
paths:
  - "app/Application/Contracts/**/*Repository*.php"
```

**Structure**: two sections inside one file — the file scopes broadly, the content addresses both subtypes.

**Shared rule** (applies to every matched file):
- `app/Application/CLAUDE.md:60-69` — interface must declare `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException` for any method using `DatabaseGateway::transact()/query()`. Both write and query interfaces call through `DatabaseGateway`, so both carry these `@throws`.

**Write-interface section** (applies to `*RepositoryInterface.php` that are not `*QueryRepositoryInterface.php`):
- `app/Infrastructure/CLAUDE.md:6` — interface lives in `Application/Contracts/` and extends `RepositoryWriteInterface`
- Paired with an `Eloquent*Repository` implementation

**Query-interface section** (applies to `*QueryRepositoryInterface.php`):
- Read-only projections — no `save()`, `update()`, `delete()`, etc. methods on the interface
- Typically backed by a view (e.g. `ProductViewQueryRepositoryInterface` → `catalog.products_view`)
- Does NOT extend `RepositoryWriteInterface`

## Files Modified

| File | Action |
|---|---|
| `.claude/rules/eloquent-repositories.md` | **Create** |
| `.claude/rules/eloquent-write-models.md` | **Create** |
| `.claude/rules/eloquent-view-models.md` | **Create** |
| `.claude/rules/migrations.md` | **Create** |
| `.claude/rules/repository-contracts.md` | **Create** |
| `app/Infrastructure/CLAUDE.md` | Remove lines 3-7, 49-59. Replace with one-line pointer: `> Eloquent repository patterns → .claude/rules/eloquent-repositories.md (auto-loads on Eloquent*Repository.php)` |
| `app/Infrastructure/Shopwired/Models/CLAUDE.md` | Remove general model rules (lines 1-36 content). Replace with one-line pointer to `eloquent-models.md`. Keep Shopwired-specific child-table section. |
| `database/CLAUDE.md` | Remove lines 65-80 (multi-schema `$table` and migration naming). Replace with one-line pointers. Keep everything else intact. |
| `app/Application/CLAUDE.md` | Remove lines 60-69 (`DatabaseGateway` interface throws table). Replace with pointer to `repository-contracts.md`. |
| Root `CLAUDE.md` | No change — `DatabaseGateway` rule stays; mirrored (not moved) into repo rule |

**Pointer rationale**: a one-line pointer preserves discoverability for humans browsing CLAUDE.md without paying per-turn token cost for rules that only apply in narrow contexts. If the `.claude/rules/` loading mechanism proves flaky, the pointer also tells a human where to look.

## Existing Utilities Referenced (not re-creating)

- `AbstractEloquentRepository` at `app/Infrastructure/Repositories/AbstractEloquentRepository.php` — rule cites it, does not modify
- `EloquentDomainMappableInterface` — rule cites it
- `AutoDomainMappingTrait` at `app/Infrastructure/Concerns/AutoDomainMappingTrait.php` — rule cites it
- `StockItemSupplierModel::attributesFromDomain()` — canonical pattern example
- `DatabaseGateway` — cited in repo rule and contracts rule

## Version Control

`.claude/rules/` is not matched by `.gitignore` (which only excludes `/.claude/scheduled_tasks.lock` under that directory), so rule files are committed with the repo by default. This is correct — they are team-shared conventions.

## Execution Order

1. **Pilot**: Create `.claude/rules/eloquent-repositories.md` only. Start a fresh Claude Code session, open `app/Infrastructure/Catalog/CustomFields/Repositories/EloquentCustomFieldRepository.php`, and confirm the rule content appears in Claude's context (ask Claude to quote the rule verbatim). If it does not load, iterate on the glob syntax (`**/Eloquent*Repository.php` vs absolute, with/without globstar prefix) until it does. **Do not proceed without confirmation.**
2. **Negation pilot**: Create `eloquent-write-models.md` with the `!**/Models/*ViewModel.php` negation. Open a `ProductViewModel.php` in a fresh session and confirm the write-models rule does **not** load. If negation isn't supported, drop it from the glob and rely on the rule content's disclaimer ("This rule applies to write-path models only — for `*ViewModel.php` see `eloquent-view-models.md`").
3. Create the remaining rule files (`eloquent-view-models.md`, `migrations.md`, `repository-contracts.md`).
4. Strip the migrated rules from source CLAUDE.md files; insert pointer lines.
5. Verify by opening one file from each target pattern and confirming the corresponding rule loads.

## Verification

- **Pilot check**: After step 1, open a matching `Eloquent*Repository.php`. Ask Claude (in a fresh session) to restate the repository creation checklist. It should reproduce the content from `eloquent-repositories.md`.
- **Non-match check**: Open an API client file (e.g. `app/Infrastructure/Linnworks/Clients/StockItemClient.php`). The repo rule should *not* appear — confirming `paths:` scoping works as expected.
- **CLAUDE.md thinness**: `app/Infrastructure/CLAUDE.md` and `app/Infrastructure/Shopwired/Models/CLAUDE.md` byte counts should drop by ~30-50%.
- **No regressions**: `make lint` and `make test` unaffected — this is pure documentation movement, no code changes.

## Out of Scope

- Moving API-client patterns (Linnworks/Shopwired/Mixpanel clients) — different axis, future rules files if valuable.
- Moving testing patterns — covered by `tests/CLAUDE.md` which stays.
- Creating an Infrastructure-wide `repositories.md` umbrella rule — premature until we see how `.claude/rules/` scales.
- Deleting `app/Infrastructure/Shopwired/Models/CLAUDE.md` — its integration-specific content should stay.
