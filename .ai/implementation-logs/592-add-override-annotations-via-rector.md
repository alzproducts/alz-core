---
# Implementation Log: #592 — chore: apply #[Override] annotations across the codebase via Rector

## Issue Context

PHP 8.3's `#[\Override]` attribute enforces at compile time that a method actually overrides a parent/interface method. Without it, renaming a parent method silently turns the child override into a new unrelated method.

Rector ships `AddOverrideAttributeToMethodsRector` inside the PHP 8.3 set. Our `rector.php` currently only enables `php84`, so the rule doesn't run. Task:

1. Enable the PHP 8.3 Rector rule set (or cherry-pick the specific rule).
2. Dry run to see the diff size.
3. Apply across `app/` and `tests/`.
4. Verify tests + lint stay green.

## Implementation

### Config investigation finding

The issue's premise that `rector.php` needs `php83: true` added was incorrect. `withPhpSets(php84: true)` already resolves to ALL PHP sets up to and including 8.4 (verified in `vendor/rector/rector/src/Configuration/PhpLevelSetResolver.php:19` and `RectorConfigBuilder::withPhpSets` at line 418: "All rules up to this version will be used"). The Override rule (`AddOverrideAttributeToOverriddenMethodsRector`) has been enabled all along — it just hadn't been applied to the codebase.

Also confirmed: passing both `php83: true, php84: true` errors out ("Pick only one version target").

**Conclusion**: no `rector.php` change needed. The acceptance criterion "rector.php enables the PHP 8.3 rule set" is already satisfied.

### Scope triage

Full dry-run (`make rector-dry-run`) showed **487 files** needing changes from **52 distinct rules** — far beyond the issue's scope. The project simply hasn't run `make rector` in a long while.

Dry-run with `--only=Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector` showed **114 files** needing only `#[\Override]` insertions.

### Applied change

Ran `vendor/bin/rector process --only='Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector'` (CLI-scoped, no config change) → 114 files modified, 118 insertions, 0 deletions. All additions are `#[\Override]` attribute lines on methods that override parent/interface methods (controllers, jobs, exceptions, value objects, data classes, service providers, use cases).

Verified the acceptance criterion: re-running the same dry-run shows zero Override-related diffs.

## Test Results

- `make test-quick`: **1608 passed** (2984 assertions), 6.60s. Exit 0.
- `make test` (full): **3194 passed** (7333 assertions), 18.14s, 6 warnings + 12 notices, Exit 2.
  - Warnings/notices are about "Class `App\Application\ContactSubmission\UseCases\SubmitContactFormUseCase` is not a valid target for code coverage" — **pre-existing on `develop`**. Verified by stashing changes and running `make test` on the baseline: identical output (`6 warnings, 12 notices, 3194 passed`, exit 2). Not introduced by this PR.
- No test failures. No tests needed to be updated for Override annotations (attribute is non-behavioural).

## Lint Results

### Linter results (final)

- **Pint**: clean (auto-converted Rector's `#[\Override]` → `use Override; #[Override]`)
- **PHPStan**: clean (exit 0)
- **PHPArkitect**: ✅ 0 violations
- **Deptrac**: ✅ 0 violations, 12039 allowed
- **TLint**: LGTM

### Linter fixes required (approved by user)

**1. `phparkitect.php` — Override allowlist**

`Override` was already in the Application + Infrastructure allowlists (lines 163, 225). Added it to the Domain (Rule 1) and Presentation (Rule 4) allowlists too — it's a PHP built-in attribute in root namespace, same status as `Throwable` / `DateTimeImmutable`, which were already listed.

**2. `app/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRule.php` — bug fix**

The rule's docstring claims "Counts all lines between opening and closing braces," but `measureLength()` used `$node->getStartLine()`, which includes attribute lines. Result: adding `#[Override]` pushed methods from 20 → 21 lines (false positive).

Fix: walk `$node->attrGroups` and move `$startLine` past the last attribute group end line. Aligns the implementation with the documented intent.

Also added `'toArray'` to `EXCLUDED_METHODS` per user request — `toArray()` is a structural serialisation method whose length tracks the number of fields, not complexity (same rationale as `toDomain`, `fromDomain`, `casts`, etc. that were already excluded).

**3. `phpstan-complexity-baseline.neon` — baseline updates**

- Updated 2 existing `excessiveClassLength` entries to reflect line counts shifted by the new `use Override;` import + `#[Override]` attributes (allowed per CLAUDE.md: "Only update existing entries when line counts shift"):
  - `EloquentOrderRepository`: 526 → 528
  - `EloquentProductRepository`: 945 → 948
- Removed 2 baseline entries that became stale and triggered `ignore.unmatched`:
  - `OrderQueryParams::toArray()` — now exempt via `EXCLUDED_METHODS`
  - `ProductSearchFeedServiceProvider::register()` — now passes with the attribute-skip bug fix

## Handoff Notes

### Files changed

**Core change (Rector output, 114 files)**
- 80 files in `App\Infrastructure` (models, repositories, responses)
- 15 files in `App\Presentation` (API resources, HelpScout resources, form requests, service providers)
- 19 files in `App\Domain\Exceptions` (+ User.php)
- All additions: `use Override;` import + `#[Override]` attribute

**Linter support changes**
- `phparkitect.php` — added `'Override'` to Domain (Rule 1) and Presentation (Rule 4) allowlists
- `app/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRule.php` — bug fix (skip attribute lines) + added `'toArray'` to `EXCLUDED_METHODS`
- `phpstan-complexity-baseline.neon` — 2 line-count updates + 2 stale entries removed

### Scope deviations vs. issue

- **No change to `rector.php`**: the issue assumed `php83` set wasn't enabled, but `withPhpSets(php84: true)` already includes all prior sets. Verified in Rector source.
- **Applied via `--only=` CLI flag**, not `make rector`: the full `make rector-dry-run` found 487 files of pending changes across 52 rules (accumulated over a long time without running Rector). Applying all would have far exceeded this PR's stated scope. The user can address the rest as a separate clean-up if desired.
- **Linter config changes required user approval** (two rounds): adding `Override` to PHPArkitect allowlists, and the PHPStan rule bug fix + `toArray` exclusion. Both explicitly confirmed via AskUserQuestion.

### Areas worth a deeper look

1. **`ExcessiveMethodLengthRule` attribute-skip fix** — This is a real improvement to the rule (aligns with its docstring) but no unit tests were added for `measureLength()`. Consider adding a fixture test if the DevTools/PHPStan rules are unit-tested elsewhere.
2. **Rest of the 373 pending Rector changes** — These are legitimate refactors from existing rule sets (deadCode, codeQuality, typeDeclarations, Laravel sets). Consider filing a follow-up issue to run them in controlled batches.
3. **Pre-existing test warnings** — The `ContactSubmission::SubmitContactFormUseCase is not a valid target for code coverage` warnings exist on `develop` independent of this PR. Might warrant a separate investigation.

### PR description draft

**Title**: `chore(rector): apply #[Override] annotations across codebase (#592)`

**Summary**
- Adds `#[Override]` attribute to 114 methods across `App\Domain`, `App\Application`, `App\Infrastructure`, `App\Presentation`, and `App\Models` using Rector's `AddOverrideAttributeToOverriddenMethodsRector` (already enabled via `withPhpSets(php84: true)`).
- Fixes a bug in the custom `ExcessiveMethodLengthRule` where attribute lines were counted as method body — now skips past `attrGroups` end line before measuring.
- Adds `Override` to Domain and Presentation PHPArkitect allowlists (already present in Application/Infrastructure).
- Adds `toArray` to the method-length rule's exclusion list (structural serialisation method).
- Updates 2 baseline line counts and removes 2 stale baseline entries rendered obsolete by the above fixes.

**Test plan**
- [x] `make test-quick` → 1608 passed
- [x] `make test` → 3194 passed (6 pre-existing code-coverage warnings, verified identical on `develop`)
- [x] `make lint` → clean
- [x] Re-running `rector process --dry-run --only='AddOverrideAttributeToOverriddenMethodsRector'` reports 0 changes (acceptance criterion)

