# Implementation Log: #641 — Migrate Eloquent Rules into Scoped .claude/rules Files

## Issue Context

Eloquent conventions are spread across six CLAUDE.md files and load on every file Claude touches in those directories — including API clients, controllers, and use cases that have nothing to do with Eloquent. Claude Code supports filename-scoped rules via `.claude/rules/` with a `paths:` frontmatter field, allowing rules to attach only when Claude opens a matching file.

Plan: `.ai/plans/2026-04-24_641-eloquent-rules-scoped-claude-rules.md`

## Implementation

### Sub-task 1: Pilot — Create `.claude/rules/eloquent-repositories.md` ✅

Created `.claude/rules/eloquent-repositories.md` with `paths:` glob targeting `Eloquent*Repository.php` and `AbstractEloquentRepository.php`.

**Pilot verified**: User confirmed the rule loads automatically when a second Claude session opened `AbstractEloquentRepository.php` — IDE showed "Loaded rules" notification and Claude was able to quote the repository creation checklist verbatim.

Content extracted from:
- `app/Infrastructure/CLAUDE.md` lines 3-7 — repository creation checklist
- `app/Infrastructure/CLAUDE.md` lines 49-59 — `Model::attributesFromDomain()` mapping pattern + bulk insert rule
- Root `CLAUDE.md` — `DatabaseGateway` vs `DB::` rule (mirrored, not moved)
- `app/Application/CLAUDE.md` — `@throws` table for `DatabaseGateway` (mirrored)

### Sub-task 2: Create remaining rule files ✅

Created four additional rule files:

- `.claude/rules/eloquent-write-models.md` — paths glob `app/Infrastructure/**/Models/*Model.php` with negation `!app/Infrastructure/**/Models/*ViewModel.php`. Contains `EloquentDomainMappableInterface`, `AutoDomainMappingTrait`, `$guarded`, schema-qualified `$table`, `attributesFromDomain` mapper. Includes fallback disclaimer so if the `!` negation syntax is unsupported, behaviour is still correct.
- `.claude/rules/eloquent-view-models.md` — paths glob `app/Infrastructure/**/Models/*ViewModel.php`. New content codifying the read-only ViewModel pattern (no `EloquentDomainMappableInterface`, no writes, paired with write `*Model.php`, `/** @property */` docblocks).
- `.claude/rules/migrations.md` — paths glob `database/migrations/**/*.php`. Migration filename convention + schema-qualified `Schema::create/table` + Postgres 63-char identifier truncation pitfall.
- `.claude/rules/repository-contracts.md` — paths glob `app/Application/Contracts/**/*Repository*.php`. Shared `@throws` section + Write-repo section (`RepositoryWriteInterface` extension, paired with Eloquent repo) + Query-repo section (`*QueryRepositoryInterface.php`, read-only, does NOT extend `RepositoryWriteInterface`).

### Sub-task 3: Strip migrated content from source CLAUDE.md files ✅

Replaced moved content with one-line pointers in:

- `app/Infrastructure/CLAUDE.md` — repo creation checklist (§ Eloquent Repositories) and model mapping rules (§ Domain-to-Model Mapping + § Bulk Inserts) replaced with pointers.
- `app/Infrastructure/Shopwired/Models/CLAUDE.md` — General model rules (lines 1-36) replaced with two pointers (write model + view model). Child-table + sync strategy sections preserved (Shopwired-specific, not cross-cutting).
- `database/CLAUDE.md` — Multi-schema `$table` line and migration naming convention replaced with pointers. Forbidden commands, `make db-reset-full`, Octane safety, connection names — all retained (safety-critical, must load unconditionally).
- `app/Application/CLAUDE.md` — Interface `@throws` table replaced with pointer. Rest of file unchanged.
- Root `CLAUDE.md` — unchanged (`DatabaseGateway` rule stays; mirrored, not moved, into repo rule).

**Files created**: 5 rule files in `.claude/rules/` (~170 lines total).
**Files modified**: 4 source CLAUDE.md files (net reduction ~80 lines of directory-wide context).

### Sub-task 4: Review pass — trim duplication, active voice, drop non-editing-time content ✅

Post-pilot review (sequential-thinking) surfaced four classes of issues. All fixed:

- **Duplication between sister rules** — `eloquent-repositories.md` §Bulk Inserts was redundant with `eloquent-write-models.md`; the bulk-insert timestamp rule is a mapper-method concern, not a repo-author one. Dropped from repo rule.
- **Passive narration → active DO / DO NOT directives** — `eloquent-write-models.md` §Attribute Mapping Method rephrased ("The method does NOT include…" → "DO NOT include…"). Same in `eloquent-view-models.md` §Conventions.
- **Architectural decision-time content in editing-time rules** — `eloquent-view-models.md` §When to Introduce a ViewModel removed (if you're already editing a ViewModel, that decision is made). `repository-contracts.md` opening paragraph on Dependency Inversion removed — architecture framing, not a rule.
- **Over-long explanations** — `repository-contracts.md` §Shared: @throws compressed from a 2-sentence rationale with a parenthetical aside to one sentence. Kept the directive + table intact.
- **Minor duplication** — schema list (`shopwired.*, linnworks.*, catalog.*, access.*`) removed from `migrations.md` since it already lives in `eloquent-write-models.md` §Model Defaults.

No content was invented or moved back to CLAUDE.md during the review — purely sharpening.

## Test Results

`make test-quick`: ✅ 1624 passed (3005 assertions) in 8.55s. Pure documentation movement — no test impact expected or observed.

## Lint Results

`make lint`: ✅ All pass.

- Pint: passed
- PHPStan: 0 errors (1222 files)
- PHPArkitect: No violations
- Deptrac: 0 violations (12095 allowed)
- TLint: LGTM

No linting fixes required — no PHP code touched.

## Handoff Notes

### What remains to verify (suggested next-session checks)

1. **Negation pilot** — Open `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` in a fresh session. Expected: `eloquent-view-models.md` loads; `eloquent-write-models.md` does NOT load. If the `!` negation is unsupported, both may load — the write-models rule already contains a disclaimer pointing to `eloquent-view-models.md`, so behaviour is still correct.
2. **Write-models load** — Open `app/Infrastructure/Catalog/Product/Models/ProductModel.php`. Expected: `eloquent-write-models.md` loads.
3. **Migrations rule** — Open any file under `database/migrations/`. Expected: `migrations.md` loads.
4. **Repo contracts rule** — Open `app/Application/Contracts/Catalog/CustomFieldRepositoryInterface.php` (write) and `app/Application/Contracts/Catalog/ProductViewQueryRepositoryInterface.php` (query). Expected: `repository-contracts.md` loads for both.
5. **Non-match check** — Open `app/Infrastructure/Linnworks/Clients/StockItemClient.php`. Expected: none of the new rules load (API client, no scope match).

### Design notes

- Safety-critical content (forbidden `migrate:fresh` commands, `make db-reset-full`, Octane static-variable warning, connection-name behaviour) **remained in `database/CLAUDE.md`** — these must load regardless of which file is open, so scoped `.claude/rules/` is the wrong home.
- Root `CLAUDE.md` was left unchanged — the `DatabaseGateway` rule is broadly applicable (governs tinker scripts, ad-hoc queries, etc.) and is mirrored (not moved) into `eloquent-repositories.md` for the focused repo-editing context.
- The `eloquent-write-models.md` rule carries a disclaimer in the body in case the `paths:` negation dialect is unsupported — so if both write and view rules load on a ViewModel, the reader is still redirected to the correct rule.
