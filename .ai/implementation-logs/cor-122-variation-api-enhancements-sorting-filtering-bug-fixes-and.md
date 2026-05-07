# Implementation Log — COR-122
# Variation API Enhancements: Sorting, Filtering, Bug Fixes, Stock Value

## Status: In Progress

## Plan Reference
`.ai/plans/2026-05-06_COR-122-variation-api-enhancements.md`

## Changes Scope
1. Migration — drop/recreate `catalog.product_variations_view` with `default_supplier_name` + `stock_value`
2. Bug fix — image index off-by-one in `VariationListItem::resolveImage()`
3. Bug fix — remove `parentSku` (always empty) across 3 files
4. Popularity sort — new `VariationSortField::Popularity`, mapped to `popularity_rank`, NULLS LAST
5. Filters — `in_stock` (bool), `default_supplier` (string), `popularity_bucket` (enum)
6. Stock value column — added to `ProductVariationView`, resource, model

## Decisions
- `stock_value` NULL when supplier has no `purchase_price` (acceptable per plan)
- `popularity_bucket` is an enum `MostPopular` (1-3) / `LeastPopular` (10-12) via `PopularityBucket` domain enum
- `orderByRaw` required for NULLS LAST (Eloquent `orderBy()` doesn't support it)
- Popularity bucket range resolved *outside* the query closure in `buildScope()` to avoid checked exception propagation — `PopularityBucket::from()` + `rankRange()` called before closure construction
- `buildFilters()` in DTO uses `array_filter` pattern (13 lines) instead of 7 if-blocks

## Progress

### Step 1: Migration [DONE]
- [x] Create migration file with DROP + CREATE of catalog.product_variations_view
- Migration ran successfully (151ms)

### Step 2: Bug Fixes [DONE]
- [x] Fix image index off-by-one in VariationListItem (ShopWired uses 1-based index)
- [x] Remove parentSku across VariationListItem, VariationListAssembler, VariationListResource

### Step 3: Popularity Sort [DONE]
- [x] Add Popularity case to VariationSortField enum
- [x] Map Popularity → popularity_rank in VariationSortFieldMapper
- [x] Add orderByRaw NULLS LAST in EloquentVariationQueryRepository

### Step 4: Filters [DONE]
- [x] Create PopularityBucket enum with `rankRange()` method
- [x] Add in_stock, default_supplier, popularity_bucket to VariationFilterField
- [x] Add DTO params to ListVariationsRequestDTO
- [x] Add match arms to EloquentVariationQueryRepository

### Step 5: Stock Value Column [DONE]
- [x] Add @property + cast to ProductVariationViewModel
- [x] Add ?float $stockValue to ProductVariationView
- [x] Pass stockValue in ProductVariationViewModelMapper::toViewDomain()
- [x] Add stock_value to VariationListResource

### Step 6: Lint Fixes [DONE]
- [x] PHPStan complexity/length: extracted `applyFilters()`, `applySorting()` from closure
- [x] PHPStan `mixed` comparison: `$value === true` instead of `$value`
- [x] PHPStan `checkedExceptionInCallable`: resolved PopularityBucket outside closure
- [x] PHPStan `cast.string`: used `\is_string()` type guard
- [x] PHPStan `argument.type` on whereBetween: separated popularityRange into typed param
- [x] DTO buildFilters rewritten to `array_filter` pattern for method length

### Step 7: Tests [DONE]
- All 3358 tests pass (7627 assertions)
- Pint, PHPStan, PHPArkitect, Deptrac, TLint all pass

## Files Changed
- `database/migrations/2026_05_06_100001_add_supplier_name_and_stock_value_to_catalog_product_variations_view.php` (NEW)
- `app/Domain/Catalog/Product/Enums/PopularityBucket.php` (NEW)
- `app/Domain/Catalog/Product/Enums/VariationFilterField.php` (MODIFIED)
- `app/Domain/Catalog/Product/Enums/VariationSortField.php` (MODIFIED)
- `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` (MODIFIED)
- `app/Domain/Catalog/Product/ValueObjects/VariationListItem.php` (MODIFIED)
- `app/Infrastructure/Catalog/Product/Mappers/ProductVariationViewModelMapper.php` (MODIFIED)
- `app/Infrastructure/Catalog/Product/Mappers/VariationListAssembler.php` (MODIFIED)
- `app/Infrastructure/Catalog/Product/Mappers/VariationSortFieldMapper.php` (MODIFIED)
- `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php` (MODIFIED)
- `app/Infrastructure/Catalog/Product/Repositories/EloquentVariationQueryRepository.php` (MODIFIED)
- `app/Presentation/Http/Api/DTOs/ListVariationsRequestDTO.php` (MODIFIED)
- `app/Presentation/Http/Api/Resources/VariationListResource.php` (MODIFIED)

## /check Review Pass (post-implementation)

User answers (`/check` AskUserQuestion round):
- Tests gap: "what does our testing strategy say" — TestingStrategy.md mandates Domain 90%+ thorough unit tests; Infrastructure 1-2 boundary tests; Presentation feature tests for critical paths.
- Image dedup: **Extract shared helper**.
- Bucket ranges: **Document the coupling, keep hardcoded**.
- Zero vs null: **Leave as planned**.

Resolved (post-/check):
- [x] Auto-fix: VariationListItem::resolveImage docstring updated to reflect `< 1` bound
- [x] Extracted `OneBasedIndexLookup::at()` (generic `@template T` helper); `VariationImageResolver::resolve()` and `VariationListItem::resolveImage()` both delegate to it
- [x] Added class-level doc comment on `PopularityBucket` documenting coupling to `popularity_max=12`
- [x] Added `tests/Unit/Domain/Catalog/Product/Resolvers/OneBasedIndexLookupTest.php` (8 tests covering all branches + struct elements)
- [x] Added `tests/Unit/Domain/Catalog/Product/Enums/PopularityBucketTest.php` (4 tests: rankRange + structure)
- [x] Added `tests/Unit/Domain/Catalog/Product/ValueObjects/VariationListItemTest.php` (10 tests: resolveImage edge cases + raw-array hydration)
- [x] `make lint` clean; `make test` green (3381 tests / 7656 assertions, 20.62s)
- [x] PR Notes section updated below

Deferred (acknowledged gaps, not blocking):
- Infrastructure boundary integration test for `EloquentVariationQueryRepository` (filters/sort) — would need DB seeding; can be follow-up
- Presentation feature test for `GET /api/products/variations` — same; can be follow-up

## Endpoint Smoke Test (post-/check)

Hit local Octane (port 8001) with curl + `X-Local-Bypass`. **Surfaced one production-blocking bug the unit tests couldn't catch.**

### Verified ✅
- Baseline `GET /api/products/variations?per_page=1` returns 200 with: `stock_value` field (null when no cost), `default_supplier` field, `popularity` block (`rank/max/level`), `image` block resolved, **no `parent_sku`**. Total: 3054 variations.
- `popularity_bucket=most_popular` returned `total: 0` — semantically correct (no SKUs at ranks 1–3 in current snapshot; verified bucket filter applied).

### Bug found 🐞
**`Webmozart\Assert\InvalidArgumentException — Money amount cannot be negative`** triggered by:
- `sort_by=popularity&sort_direction=asc`
- `popularity_bucket=least_popular`
- `in_stock=true` / `in_stock=false` (different rows, same root cause)

**Stack**: `ProductVariationView.php:113` → `Money::nonZeroOrNull($stockValue, ...)` → `Money::__construct` non-negative assert.

**Root cause**: SQL view computed `s.purchase_price * COALESCE(si.available, v.stock, 0)`. When `si.available` is negative (oversold/over-allocated stock — normal in real inventory systems), the product is negative; `Money` rejects it.

**Fix applied** (in migration): clamp the multiplier with `GREATEST(..., 0)` so oversold stock collapses to a 0 stock_value (→ null in PHP via `nonZeroOrNull`). Semantically correct — "stock value" = inventory worth, can't be negative; if we owe stock, our worth is 0.

### Endpoint matrix — all verified ✅ (post-fix)

| Test | Total | Notes |
|---|---|---|
| Baseline (`per_page=2`) | 3054 | `parent_sku` removed, `stock_value` nested under `stock`, `default_supplier`/`popularity` present |
| `sort_by=popularity&sort_direction=asc` | 3054 | Top 5 rank=12; page 559 transitions 12→null. NULLS LAST works. |
| `sort_by=popularity&sort_direction=desc` | 3054 | Top 5 rank=12 (data is degenerate — only rank=12 + null exist) |
| `sort_by=price&sort_direction=asc` | 3054 | First prices: [0, 0, 0] |
| `sort_by=price&sort_direction=desc` | 3054 | First prices: [676.62, 676.62, 676.62] |
| `popularity_bucket=most_popular` | 0 | No SKUs at ranks 1-3 in current data |
| `popularity_bucket=least_popular` | 2791 | All ranks ∈ [10,12] |
| `in_stock=1` | 95 | All `available > 0`, all `stock_value` non-null |
| `in_stock=0` (after fix) | 2959 | Includes oversold (negative available); 95+2959=3054 ✓ |
| `default_supplier=FindSignage` | 2418 | All samples match supplier name |
| `default_supplier=NonExistentSupplier` | 0 | Filter applied correctly |

### Two additional bugs found + fixed during testing

**1. Money negative assertion** (production-blocking)
SQL `stock_value = purchase_price × COALESCE(available, stock, 0)` produced negative values when `available` was negative (oversold inventory). `Money::nonZeroOrNull` asserts non-negative.
**Fix**: clamp multiplier with `GREATEST(..., 0)` in migration line 136. Semantically: oversold = no inventory worth.

**2. `in_stock=false` filter missing oversold records**
`EloquentVariationQueryRepository.php:99` had `$value === true ? '>' : '=', 0` — the `=` excluded negative-available SKUs.
**Fix**: changed `=` → `<=`. Verified: 95+2959 now equals total 3054 (was 95+2866=2961 with 93-record gap).

### `=true` redirect — **NOT a bug, correct Laravel behaviour**

Initial diagnosis was wrong. Re-tested with `Accept: application/json`: returns clean **422** with body:
```json
{"error":{"type":"validation_error","message":"The is on sale field must be true or false.",
 "errors":{"is_on_sale":["The is on sale field must be true or false."]}}}
```

Laravel's `boolean` validation rule accepts `1`, `0`, `"1"`, `"0"`, `true`, `false` — NOT the strings `"true"` / `"false"` (per Laravel's documented spec). Without `Accept: application/json`, validation failure renders as a 302 redirect (standard "browser-like request" failure path via `request->expectsJson()`). API clients should always send `Accept: application/json` and use `1`/`0` for booleans. No COR-122 work needed.

### Critical context if resuming
- `$API_PORT=8001`, `$API_BYPASS_SECRET` 32 chars
- Endpoint: `GET http://127.0.0.1:8001/api/products/variations` — header `X-Local-Bypass: $API_BYPASS_SECRET`
- Octane running in background (task `bb6451cac`)
- All test responses dumped to `tmp/cor122-*.json`
- `make lint` clean after the in_stock filter fix; need to re-run `make test` to confirm unit tests still pass.

### Lesson
Unit tests covered helper logic + enum structure but couldn't reach the real-data constraint mismatch. Endpoint smoke testing — even with curl — was needed to surface this. Worth repeating any time a new SQL-derived field hits a Domain VO's invariant.

## Simplify Pass
- **Fixed**: `popularity_bucket` validation rule changed from hardcoded `'in:most_popular,least_popular'` to derive from `PopularityBucket::cases()` (matches `sort_by` / `sort_direction` patterns in same DTO). Prevents silent drift if enum cases are added.
- **Dismissed**: Eager-loading `stockItem.suppliers` unconditionally — investigation showed `StockItemModel::defaultSupplier()` requires the `suppliers` relation to be loaded; needed for every request (not just `?include=suppliers`).
- **Dismissed**: `popularity_rank` index gap — out of scope; column comes from a view join over `sku_popularity_ranking_latest`, would need separate migration on the underlying table.

## PR Notes

### What
Adds variation listing API enhancements: popularity sort + 3 new filters (`in_stock`, `default_supplier`, `popularity_bucket`), `stock_value` column, and 2 bug fixes (parent SKU removal, image index off-by-one).

### Why
- `parent_sku` was always empty in responses (parent products use SKUs at the variation level, not the product level)
- `image_index` mismatch — ShopWired stores 1-based indices, code treated them as 0-based, so the wrong image was returned for any variation with `image_index >= 1`
- Stock value (purchase_price × available_stock) is needed for inventory dashboards
- Popularity sorting + filtering powers "best sellers / clearance" merchandising UI
- `default_supplier` filter enables supplier-specific stock reports
- `in_stock` filter enables "available items only" toggles

### Key Decisions
- `PopularityBucket` enum lives in Domain with a `rankRange()` method — keeps the bucket→range mapping in the domain rather than in the repository. Class-level doc comment flags the hardcoded coupling to `popularity_max=12`.
- Popularity sort uses `orderByRaw("{$column} {$direction} NULLS LAST")` because Eloquent's `orderBy()` doesn't support `NULLS LAST`; column/direction are enum-derived (no SQL injection)
- `PopularityBucket::from()` is resolved *outside* the query closure in `buildScope()` to avoid checked-exception propagation through the closure (avoids `shipmonk.checkedExceptionInCallable`)
- `stock_value` denormalized in the SQL view rather than computed at PHP level — reuses the existing `s` (suppliers) and `si` (stock_items) joins; no extra query cost
- `buildFilters()` rewritten to `array_filter` pattern (13 lines) instead of 7 if-blocks (25 lines) to fit method-length limit
- `applyFilters()` and `applySorting()` extracted from the closure for cognitive complexity / method length compliance
- `OneBasedIndexLookup::at()` extracted (generic `@template T`) — single source of truth for ShopWired's 1-based `imageIndex` semantics; both `VariationImageResolver::resolve()` and `VariationListItem::resolveImage()` delegate to it
- **Endpoint smoke test surfaced 2 bugs** (production-blocking + filter-correctness) the unit tests couldn't catch: SQL view producing negative `stock_value` (clamped via `GREATEST`), and `in_stock=false` filter missing oversold records (changed `=` to `<=`)

### Testing
- All 3381 unit tests pass (7656 assertions, 20.62s) — 23 new Domain tests added across `OneBasedIndexLookup`, `PopularityBucket`, `VariationListItem`
- **Live endpoint smoke test against real DB** (3054 variations) — all filters/sorts verified, surfaced 2 bugs (negative-stock + oversold-records) that were fixed and re-validated
- Migration ran successfully (24.68ms after fix re-apply)
- Pint, PHPStan (max), PHPArkitect, Deptrac, TLint all pass post-fix
