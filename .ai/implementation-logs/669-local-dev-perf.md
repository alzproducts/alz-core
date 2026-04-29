# Implementation Log: Local Dev Performance Fixes

**GitHub Issue**: #669
**Plan Document**: .ai/plans/2026-04-29_669-local-dev-perf.md
**Branch**: `feature/669-local-dev-perf`
**Status**: Implementation Complete (awaiting commit + PR)
**Started**: 2026-04-29
**Completed**: 2026-04-29

## Overview

Two compounding fixes that make the local dev server usable again:
1. Per-request dedupe of unknown-custom-field warnings (537/req → 1/req).
2. Tighten Octane file-watch globs so only `.php` saves restart workers.
3. Make `make db-reset-full` populate `shopwired.custom_field_definitions` so the warning flood never starts.

## Decision Log

### 2026-04-29
- **Decision**: Honor plan's branch name `feature/669-local-dev-perf` despite `bug` label suggesting `bugfix/`.
- **Why**: Plan/spec trumps auto-detection. Plan slug is short and semantic; auto-derived slug from issue title would be ~10 words.

- **Decision**: Add mutable `$warnedFieldCounts` alongside `readonly` injected params using PHP 8.4 per-property modifiers (not `readonly class`).
- **Why**: We need a mutable accumulator inside an otherwise immutable class.
- **Tradeoff**: Cannot mark the class `readonly` — must rely on per-property modifiers.

- **Decision**: Register `app()->terminating()` callback inside the constructor, not on first warning.
- **Why**: Constructor is invoked once per scoped binding. Registering at first-warning time risks not registering at all if no warning occurs (acceptable) but keeps a branch in the hot path. Keep the callback registration unconditional and cheap.

- **Decision**: Replace `make db-reset-full`'s `migrate` step with `dev:seed-sync` (verified: command exists, supports `--incl-pii`, runs migrate internally).
- **Why**: Makes the custom-fields fix permanent — `shopwired.custom_field_definitions` is repopulated by the dispatched `SyncShopwiredCustomFieldsJob` after every reset.

## Deviations from Plan

- **Decomposed `fromRawFields()`** instead of leaving the existing baseline entry. After my edit the method shrank 27→24 lines but still over the 20-line `alz.excessiveMethodLength` threshold. Initially planned to bump the existing baseline (allowed per memory rule), but on user challenge investigated the rule's design (`EXCLUDED_METHODS` is for structural listings; `fromRawFields()` has branching + side effects, doesn't fit) and the codebase precedent (decompose, never expand the allowlist for new shapes). Extracted a private `resolveTypedValue()` helper. `fromRawFields()` is now 13 lines, baseline entry deleted entirely.

## Blockers / Open Questions

- [x] Verified `dev:seed-sync` exists with `--incl-pii` flag.
- [ ] **Out of scope for this PR**: `ProductFilterFactory::fromRawFilters()` (`app/Infrastructure/Shopwired/Factories/ProductFilterFactory.php:60-65`) has the same per-occurrence `Log::warning` flooding pattern. Same fix applies. Worth a follow-up issue.

## Simplify Pass (2026-04-29)

- Removed dead `$this->warnedFieldCounts = []` reset inside terminating closure — under Octane the sandbox flushes after the closure runs; under non-Octane the closure isn't re-entered. Reset is unobservable.
- Removed narrating constructor comment — class-level docblock now captures the same WHY.
- `db-reset-full-pii`: added Step 1/2 + Step 2/2 echoes + drain note for parity with `db-reset-full`.
- **Verified false claim**: reviewer flagged a memory leak from accumulating terminating callbacks. Inspected `vendor/laravel/octane/src/Worker.php` (clones `$this->app` per request, `$sandbox->flush()`) and `vendor/laravel/framework/src/Illuminate/Foundation/Application.php::flush()` (clears `$terminatingCallbacks = []`). No leak.

## Technical Notes

- Files touched:
  - `app/Infrastructure/Shopwired/Factories/CustomFieldFactory.php` — dedupe warnings
  - `config/octane.php` — tighten watch globs
  - `Makefile` — db-reset-full + db-reset-full-pii

## PR Notes

### What
Fixes local-dev unusability caused by (a) a per-product warning flood from an empty `custom_field_definitions` table, and (b) Octane workers thrashing on every IDE temp-file save.

### Why
Engineers were seeing 2–6s API responses and frequent worker restarts (5,154 restarts in one day's log). Both root causes blocked normal dashboard use.

### Key Decisions
- Per-request dedupe via `app()->terminating()` so warnings are summarised, not flooded — zero request-latency impact.
- Tighten watch globs (`'app'` → `'app/**/*.php'`, `'routes'` → `'routes/**/*.php'`) so only PHP saves trigger reloads.
- `make db-reset-full` now runs `dev:seed-sync` so `custom_field_definitions` is populated post-reset, preventing the flood at source.

### Testing
Manual verification per plan §Verification — logs and endpoint timings before/after a fresh dev session.
