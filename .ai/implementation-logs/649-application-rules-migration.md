# Implementation Log: #649 — Port Application layer conventions to path-scoped .claude/rules files

## Issue Context

`app/Application/CLAUDE.md` mixes architectural guidance with tight file-shape conventions, loading all content on every file edit. This migrates four sections into path-scoped `.claude/rules/` files so they load only on matching globs:

- `*UseCase.php`: Use Case Decomposition, Async Dispatch, Typed Result Objects → `application-use-cases.md`
- `*ClientInterface.php`: Pre-Resolved Parameters → `application-client-interfaces.md`

Follows the same pattern as Eloquent (#642) and Presentation (#644/#645) migrations.

## Implementation

### Sub-task 1: Create `.claude/rules/application-use-cases.md`
- Absorbed: Use Case Decomposition (lines 105–125), Async Dispatch (lines 25–27), Typed Result Objects (from Complex Use Case Reference bullet 1)
- Glob: `app/Application/**/*UseCase.php`
- Canonical pointers: `Linnworks/UpdateCostPriceBySupplier/`, `CostPriceUpdateResult`, `PriceUpdateResult`

### Sub-task 2: Create `.claude/rules/application-client-interfaces.md`
- Absorbed: Client Interface Design: Pre-Resolved Parameters (lines 46–58)
- Glob: `app/Application/Contracts/**/*ClientInterface.php`
- Canonical pointer: existing `Contracts/*ClientInterface.php` files

### Sub-task 3: Trim `app/Application/CLAUDE.md`
- Removed: Async Dispatch section (lines 25–27)
- Removed: Pre-Resolved Parameters subsection (lines 46–58)
- Removed: Interface @throws pointer line (line 62, rolled into Per-File Conventions)
- Removed: Use Case Decomposition section (lines 105–125)
- Trimmed: Complex Use Case Reference — promoted typed-result-objects bullet to scoped rule, dropped thin-pipeline bullet as redundant
- Added: Per-File Conventions pointer list

### Content Accounting (all removed lines have a home)
| Content | Disposition |
|---|---|
| Directory Structure | Kept in CLAUDE.md |
| Async Dispatch | → `application-use-cases.md` |
| Logging | Kept in CLAUDE.md |
| Interface Placement Core Principle | Kept in CLAUDE.md |
| Pre-Resolved Parameters subsection | → `application-client-interfaces.md` |
| Interface @throws pointer | → Per-File Conventions pointer list |
| Exception Handling | Kept in CLAUDE.md |
| Use Case Decomposition | → `application-use-cases.md` |
| Complex UCR — typed result objects | → `application-use-cases.md` |
| Complex UCR — phase factory | Kept in CLAUDE.md |
| Complex UCR — match(true) on instanceof | Kept in CLAUDE.md |
| Complex UCR — thin execute() pipeline | Dropped (redundant with Decomposition rule) |
| Complex UCR — Shopwired/PricingUpdate pointer | Kept in CLAUDE.md |

## Test Results

Pure documentation change — no PHP files modified. Stop hooks (`make test`) will validate automatically on session end. Expected: green.

## Lint Results

Pure documentation change — no PHP files modified. Stop hooks (`make lint`) will validate automatically on session end. Expected: green.

## Handoff Notes

Pure documentation change — no PHP code files modified. Stop hooks will run `make fix`, `make lint`, `make test` automatically on session end.

**Key decisions:**
- Thin-pipeline bullet in Complex Use Case Reference was dropped (not moved) — it was redundant with the Decomposition rule's "keep execute() pipeline thin" guidance
- `application-client-interfaces.md` scoped to `Contracts/**/*ClientInterface.php` only (not `*Resolver.php`) — the pre-resolved-parameters rule shapes the interface's parameter list, not the resolver's body
- Directory Structure and Logging sections stay in CLAUDE.md — they're layer-wide orientation, not file-shape rules

**Concerns / areas for review:** None — follows the established Eloquent (#642) and Presentation (#644/#645) migration pattern exactly.
