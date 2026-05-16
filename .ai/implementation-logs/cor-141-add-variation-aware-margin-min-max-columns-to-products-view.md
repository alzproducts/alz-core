# Implementation Log: COR-141 — Add variation-aware margin min-max columns to products view

## Issue Context
Both `profit_margin` and `net_margin_single_unit` on `catalog.products_view` are NULL for variation-only products (parent has no SKU → no Linnworks cost price join). This blocks the downstream margin-tier label sync feature (custom_label_1).

Solution: add 4 new variation-aggregated columns (`profit_margin_min`, `profit_margin_max`, `net_margin_single_unit_min`, `net_margin_single_unit_max`) via a `parent_margins` CTE + LATERAL subquery against `product_variations_view`. Remove the parent-level `net_margin_single_unit` column (unused, added in previous migration). Existing `profit_margin` is kept unchanged.

## Implementation

### Sub-task 1: Create migration
**File:** `database/migrations/2026_05_17_100000_add_margin_min_max_to_catalog_product_views.php`

- `up()` drops both views in dependency order (products first), recreates `product_variations_view` identically, then recreates `products_view` with:
  - New `parent_margins` CTE (absorbs linnworks + fdsc joins from main FROM)
  - `fdsc` JOIN removed from main FROM (owned by CTE now)
  - `profit_margin` simplified to `pm.parent_profit_margin AS profit_margin` (same value)
  - `net_margin_single_unit` column removed
  - LATERAL subquery + INNER JOIN on `parent_margins` added
  - 4 new SELECT columns at end
- `down()` restores verbatim from `2026_05_16_100001::up()`

### Sub-task 2: Update ProductViewModel
**File:** `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php`

- Removed `@property float|null $net_margin_single_unit` docblock
- Removed `'net_margin_single_unit' => 'float'` cast
- Added 4 new `@property float|null` docblocks for the new columns
- Added 4 new `'float'` casts

## Test Results

- `make test`: 3411 passed, 1 risky (pre-existing), 12 notices (pre-existing) — no failures
- Migration rollback/re-apply: both clean (108ms rollback, 57ms re-apply)
- View column check: `net_margin_single_unit` absent; 4 new min/max columns present
- Data validation:
  - 563/1000 products have margin values (expected — those with cost data)
  - Parent products: `profit_margin_min == profit_margin_max == profit_margin` (parent wins)
  - Variation-only products (null sku): `profit_margin_min/max` populated from variation aggregates

## Lint Results

- Pint: passed (no style changes needed)
- PHPStan level max: No errors
- PHPArkitect: No violations
- Deptrac: 0 violations
- TLint: LGTM

## /check Results

No CRITICAL/HIGH/MEDIUM/LOW issues. Plan adherence verified item-by-item. Empirical validation (apply, rollback, re-apply, query view structure, query data semantics) all clean.

## Handoff Notes

- Branch: `feature/cor-141-add-variation-aware-margin-min-max-columns-to-products-view` (from `develop`)
- Uncommitted changes on disk: 2 files
  - `database/migrations/2026_05_17_100000_add_margin_min_max_to_catalog_product_views.php` (new)
  - `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` (modified)
- Linear status: In Progress
- No follow-ups needed. PHP `ProductViewPricing::aggregate()` can now optionally be simplified to read from `profit_margin_min/max` instead of `commonCostPrice()` — but that's a separate refactor (the plan explicitly lists it as out of scope).
- Performance: At ~5k catalog products the LATERAL subquery adds one per-row scan of `product_variations_view` (which itself has multiple CTEs). PostgreSQL's planner may inline it. If a slow EXPLAIN ANALYZE appears later, consider materializing `product_variations_view` or moving the variation aggregation into the CTE chain.
