# Implementation Log: #445 — feat(catalog): add UK-formatted date strings to ProductView

## Issue Context
Add UK-formatted date strings alongside raw DateTimeImmutable values in ProductView for front-end consumption.
- Add `DateFormat` value object in `App\Domain\Shared\ValueObjects` with `DEFAULT_DATE_FORMAT = 'd/m/Y'`
- Add `createdAtFormatted` and `updatedAtFormatted` properties to `ProductView`, derived in constructor
- Add test coverage for the new formatted date properties

Closes #443

## Implementation

### Files changed
- `app/Domain/Shared/ValueObjects/DateFormat.php` — New `final class DateFormat` with `DEFAULT_DATE_FORMAT = 'd/m/Y'` constant
- `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — Added `$createdAtFormatted` and `$updatedAtFormatted` public string properties, derived in constructor via `$createdAt->format(DateFormat::DEFAULT_DATE_FORMAT)`
- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php` — Test coverage for formatted date properties
- `phpstan-complexity-baseline.neon` — Updated existing `ProductView::__construct()` baseline entry from 47 → 49 lines (the original bot incorrectly bumped it to 51)

### Fix applied in this session
The autonomous Claude bot made two baseline commits trying to fix a PHPStan mismatch:
1. Bumped baseline to 49 (correct actual count after the feature addition)
2. Then incorrectly bumped again to 51 (over-corrected, causing `ignore.unmatched` error)

Fixed by correcting the baseline entry from 51 → 49, matching what PHPStan actually reports.

## Test Results
- 1389 tests passed (2517 assertions) — all green

## Lint Results
- Pint: pass
- PHPStan: no errors
- PHPArkitect: no violations
- Deptrac: no violations
- TLint: LGTM

## Handoff Notes
- Branch: `claude/issue-443-20260331-2044`
- PR: #445 — open, ready to merge
- The fix was a single-line change to `phpstan-complexity-baseline.neon` (51 → 49)
- No code logic changed; all implementation was already correct from the original commit
