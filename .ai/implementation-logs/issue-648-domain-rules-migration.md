# Implementation Log: #648 — Port Domain layer conventions to path-scoped .claude/rules files

## Issue Context

Port file-shape content from `app/Domain/CLAUDE.md`, `app/Domain/Catalog/CLAUDE.md`, and `app/Domain/Shared/Validation/CLAUDE.md` into path-scoped `.claude/rules/` files, following the same migration pattern as Eloquent (#642), Presentation (#644/#645), and Infrastructure (#647).

Detailed plan with all design decisions pre-made at `.ai/plans/2026-04-25_648-domain-rules-migration.md`.

## Implementation

### Step 1 — Create `.claude/rules/domain-exceptions.md`
Created new rule file covering Exception class shape rules (final/readonly, static messages, context(), inheritance).

### Step 2 — Create `.claude/rules/domain-validators.md`
Created new rule file covering validator placement, result class trait composition, and orFail() ownership.

### Step 3 — Create `.claude/rules/infrastructure-view-assemblers.md`
Created new rule file (in infrastructure-* family despite source being in Domain/Catalog) covering VO construction delegation from assemblers.

### Step 4 — Edit `app/Domain/CLAUDE.md`
- Removed Exception Design Rules section (lines 14–21)
- Removed Validators section (lines 37–39)
- Removed Integer IDs section (lines 41–43, redundant with Native Domain Types table IntId row)
- Added two pointer lines to the respective new rule files

### Step 5 — Delete `app/Domain/Catalog/CLAUDE.md`
Entire content ported to `infrastructure-view-assemblers.md`.

### Step 6 — Delete `app/Domain/Shared/Validation/CLAUDE.md`
Entire content ported to `domain-validators.md` (minus linter-enforced naming bullet and stale Design Report reference).

## Test Results

3256 passed (7418 assertions), 12 pre-existing notices — no failures.

## Lint Results

All five linters passed clean:
- Pint: passed
- PHPStan: No errors (1244 files)
- PHPArkitect: No violations
- Deptrac: 0 violations
- TLint: LGTM

No lint errors to fix — pure documentation movement, no PHP code changed.

## Review Pass (2026-04-25)

Code review surfaced four findings; user approved three follow-up changes:
- **New rule file** `.claude/rules/interfaces.md` — ports the dropped `@throws on interface methods` bullet from the original `app/Domain/CLAUDE.md` Exception Design Rules section. Glob: `app/**/*Interface.php` (broader than Domain because the rule is universally true). Canonical: `MixpanelClientInterface`.
- **Tightened wording** in `domain-exceptions.md` bullet 3 — dropped `(override the base implementation)` parenthetical, which was inaccurate for `\LogicException` extensions like `UnsupportedFieldException` that declare `context()` fresh rather than overriding.
- **Renamed implementation log** `648-domain-rules-migration.md` → `issue-648-domain-rules-migration.md` to match the `.ai/implementation-logs/CLAUDE.md` template (`issue-{number}-{description}.md`).

Findings deferred / kept-as-is:
- Named-constructor bullet stays dropped — verified zero current Domain exceptions use `from*` factories; documenting an absent pattern is drift-prone.

## Handoff Notes

Pure docs migration. No PHP code touched. All trimmed content accounted for in new rule files — nothing silently dropped. Two design decisions worth noting in the PR:
- Native Domain Types table kept in `Domain/CLAUDE.md` (not extracted) because its scope is genuinely "every Domain file."
- `infrastructure-view-assemblers.md` rule lives in the `infrastructure-*` family even though source content was in `app/Domain/Catalog/CLAUDE.md` — naming follows file location, not source-doc location.
- `interfaces.md` rule lives at the top level (no layer prefix) because its glob is `app/**/*Interface.php` — applies to every cross-layer interface in the codebase. Coexists with `repository-contracts.md` `@throws` section (which is repository-specific); both rules fire together on repository interfaces, with the same directive.
