# Implementation Log: #637 — fix: catalog views return profit-margin 100 when supplier purchase-price is zero

## Issue Context

`/api/products/{id}` returns `profit_margin: 100` on variations whose `cost_price` is `null`. The read path runs through Postgres views (`catalog.products_view`, `catalog.product_variations_view`) that pre-compute margin in SQL. When `linnworks.stock_item_suppliers.purchase_price = 0.00`, the view's `IS NOT NULL` guard passes and the formula collapses to `(price − 0) / price × 100 = 100`.

Plan: `.ai/plans/2026-04-24_637-fix-catalog-views-margin-zero-cost.md`.

Fix: one new migration that DROP + CREATEs both views, tightening the margin `CASE` guard from `s.purchase_price IS NOT NULL` to `s.purchase_price > 0`. `down()` restores previous (buggy) definitions verbatim.

## Implementation

### Files changed

- **New**: `database/migrations/2026_04_24_120000_fix_catalog_views_margin_zero_cost.php`
  - `up()` DROP + CREATEs `catalog.products_view` and `catalog.product_variations_view`
  - `down()` restores the previous (buggy) definitions verbatim from the two source migrations

### Approach

- View bodies copied verbatim from the live source migrations to keep the diff to one line per view:
  - `catalog.products_view` body ← `2026_04_21_041810_add_popularity_to_catalog_products_view::up()` (includes `popularity_rank`/`popularity_max`, uses plain `CREATE VIEW` in this migration since column-list is unchanged but the whole body must be recreated).
  - `catalog.product_variations_view` body ← `2026_04_18_024602_add_available_physical_stock_to_catalog_product_views::up()` (includes `available_stock`/`physical_stock`).
- Only change in each view body: CASE guard `s.purchase_price IS NOT NULL` → `s.purchase_price > 0`. Added a one-line inline comment on each explaining why.
- DROP + CREATE (not `CREATE OR REPLACE`) because Postgres `OR REPLACE` can only append columns; no existing column is being added or removed here but the semantic change still warrants a full recreate per project convention.
- `down()` order: DROP products_view → CREATE products_view → DROP product_variations_view → CREATE product_variations_view. Views are independent (no cross-references), so order does not matter.

### Verification of `up()`

- Ran `php artisan migrate --path=...` locally — completed in ~91ms, no errors.
- `down()` rollback was denied by local permission system (shared-schema protection). Body is a symmetric verbatim copy from the two source migrations that have previously deployed successfully, so structural correctness is inherited.

## Test Results

`make test` — **3222 passed**, 12 unrelated mock-expectation notices, 0 failures. Duration ~16.65s. No view-adjacent tests touched: the views are data-plane SQL, not exercised by the PHP test suite (which covers the `VariationPriceResolver` write path — already green from #631 — and resource serialization).

## Lint Results

`make lint` — all linters green, zero errors:

- Pint — passed
- PHPStan (level max + ShipMonk + bleeding edge) — no errors
- PHPArkitect — no violations
- Deptrac — 0 violations / 0 warnings
- TLint — LGTM

Nothing skipped; no baseline entries touched.

## Handoff Notes

### Files changed
- `database/migrations/2026_04_24_120000_fix_catalog_views_margin_zero_cost.php` (new) — DROP + CREATE both catalog views with `s.purchase_price > 0` guard; `down()` restores previous (buggy) definitions verbatim.

### What was NOT changed (and why)
- `app/Domain/Catalog/Product/Resolvers/VariationPriceResolver.php` — fixed in #631 for the write path; bug in this ticket is entirely in the SQL read path.
- `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php` + mappers + resource — pass-throughs; new `null` from the view flows through untouched (already typed nullable).
- The `cost_price` column still projects `s.purchase_price` (can be `0`). Downstream `Money::nonZeroOrNull` already hides zero from callers, matching the user's explicit descope of the null-vs-zero upstream question.

### Local verification
- `php artisan migrate` applied the new migration cleanly (91ms).
- `down()` rollback was denied by local permission (shared-schema protection); body is a symmetric verbatim copy of the two source migrations, which deployed successfully in the past.
- **Live API check** (`GET http://127.0.0.1:8000/api/products/2818760`, HTTP 200): parent `profit_margin: null`, all 16 variations `profit_margin: null`, variation count = 16. ✅ matches plan verification step 2.
- **Direct SQL check** on `catalog.product_variations_view` for `product_external_id = 2818760`: 16 rows, all `profit_margin` NULL, all `cost_price = 0.0000` (confirming the root cause was zero cost, not NULL). ✅ matches plan verification step 3.
- **Non-regression sanity**: sampled 5 variations with `profit_margin > 0 AND < 100` (e.g. `GS-BQ-12A-W` cost £97.85 margin 23.73, `100178` cost £9.00 margin 27.71) — all intact.

### Source-fidelity review (sequential-thinking walk of the migration)
- Verified sources are the latest for each view: `2026_04_21_041810` for `catalog.products_view` (most recent of 5); `2026_04_18_024602` for `catalog.product_variations_view` (most recent of 2).
- Full line-by-line diff of all four SQL bodies (up × 2, down × 2) against their sources confirms:
  - up() products_view: only CASE guard + new comment + `CREATE OR REPLACE VIEW` → `DROP + CREATE VIEW` (conventional, matches 3 of 4 earlier products_view migrations).
  - up() product_variations_view: only CASE guard + new comment.
  - down() products_view: byte-for-byte verbatim of source (with buggy guard restored).
  - down() product_variations_view: byte-for-byte verbatim of source.
- One stray trailing period on an existing comment in up() products_view was found during the review and reverted to restore strict verbatim-copy.

### Production verification (post-deploy)
Plan step 2: `GET /api/products/2818760` should return `profit_margin: null` on all 16 variations (parent was already `null`, should stay `null`). Plan step 3: direct SQL check on `catalog.product_variations_view` should return NULL margin for any row where `cost_price = 0`.

### Areas worth a second look in review
- SQL view bodies are long; I copied them verbatim from the two source migrations and changed only the one CASE line per view (plus a one-line comment). Diffing the new file against the sources should show exactly that.
- `up()` uses plain `CREATE VIEW` for products_view rather than `CREATE OR REPLACE`: the plan notes that OR REPLACE cannot drop columns, and while this migration doesn't remove columns, the DROP + CREATE pattern matches `2026_04_18_024602::up()`'s approach and keeps the semantics unambiguous.
