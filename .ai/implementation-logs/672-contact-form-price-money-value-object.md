# Implementation Log — Issue #672
## Contact Form Price → Money Value Object

**Branch:** `feature/672-contact-form-price-money-value-object`
**Plan:** `.ai/plans/2026-04-29_672-contact-form-price-money-value-object.md`

---

## Decisions

- `toNet(precision: null)` used in `toArray()` for lossless JSONB storage (plan says "lossless on significant digits")
- `formatNet()` calls `toNet()` with default precision=2 (rounds internally before formatting), consistent with plan intent of 2dp display
- Existing test price strings like `'£149.99'` updated to `Money::exclusive(149.99)` — symbol-prefixed strings are not valid numeric inputs

## Steps

### Step 3 — Implementation ✅
- Added `exclusiveFromString()`, `formatNet()`, `formatGross()` to `Money`
- Changed `SelectedProduct::price` to `?Money`, updated `toArray()`/`fromArray()`
- Updated `ContactSubmissionMapper::mapProduct()` (HTTP) and Infrastructure ingest mapper
- Updated transformer: label → `Price (excl VAT):`, output → `formatNet()`
- Updated all three test files
- **Discovered:** `app/Infrastructure/Ingest/ContactSubmission/Mappers/ContactSubmissionMapper.php` and `TestSlackNotificationCommand.php` also needed updating (hidden callers)

### Step 4 — Tests ✅ — 1643 passed, 0 failures
### Step 5 — Lint ✅ — All 5 linters clean after fixing ordered_imports (×3), useless cast, alz.excessiveMethodLength
### Step 7 — Simplify ✅ — Fixed `formatNet`/`formatGross` precision threading ($decimals passed to toNet/toGross), trimmed docblock
### Step 8 — Sweep ✅ — No changes needed; 3317 tests passing
### Step 9 — Validate ✅ — Write-only pipeline; covered by unit tests

---

## PR Notes

feat(contact-submission): wrap SelectedProduct price in Money value object (#672)

- Adds `Money::exclusiveFromString()`, `::formatNet()`, `::formatGross()` helpers
- Changes `SelectedProduct::price` from `?string` to `?Money` (exclusive tax type)
- Converts raw price string in `ContactSubmissionMapper::mapProduct()` — earliest layer-safe boundary
- HelpScout email now shows `Price (excl VAT): 12.34` (2dp, honest label)
- JSONB round-trip updated: stores `(string) $money->toNet(precision: null)` — lossless on significant digits, no migration needed
