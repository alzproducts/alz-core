# Variation List Endpoint (GET /api/products/variations)

## Summary

Standalone API endpoint returning variations as first-class, top-level rows — analogous to `GET /api/products` but at the SKU level. Each row is self-contained with denormalized parent context.

## Architecture

- **New composition VO**: `VariationListItem` — holds a `ProductVariationView` property (composition, not flattened) plus parent context fields
- **SQL view expansion**: `catalog.product_variations_view` gains parent columns (is_active, main_category_ids, has_free_delivery, title, sku, url, etc.) to support server-side filtering
- **Assembler**: New `VariationListAssembler` composes variation view models + parent data into `VariationListItem` domain VOs
- **API Resource**: New `VariationListResource` serializes `VariationListItem` for the response

## Confirmed Field List

### Core variation data (from ProductVariationView via composition)

| Field | Type | Notes |
|-------|------|-------|
| id | IntId | Variation external ID |
| sku | ?Sku | Variation SKU (nullable for legacy) |
| gtin | ?Gtin | Variation GTIN |
| mpn | ?string | Manufacturer Part Number |
| price | Money | Variation selling price |
| costPrice | ?Money | Variation cost price |
| salePrice | ?Money | Variation discounted price |
| rrp | ?Money | Variation RRP |
| effectivePrice | Money | Selling price after sale logic |
| profitMargin | ?float | Margin % |
| isOnSale | bool | Variation sale state |
| stock | Stock VO (full: available + physical) | Expose both fields |
| weight | ?Weight | Variation weight |
| options | list\<ProductVariationOption\> | Size, Color, etc. |
| isComposite | bool | Composite flag |
| defaultSupplier | ?ProductSupplier | Linnworks supplier |
| popularity | ?Popularity | SKU-level rank |
| createdAt | DateTimeImmutable | **NEW — surface from DB** |
| updatedAt | DateTimeImmutable | **NEW — surface from DB** |

### Denormalized parent context (on VariationListItem)

| Field | Type | Notes |
|-------|------|-------|
| parentProductId | IntId | Parent external ID |
| parentSku | ?Sku | Parent product SKU |
| variationTitle | string | Computed: parent title + delimiter + option values. Fallback: just parent title when options empty |
| links | VariationLinks | public_url = parent URL + `?var={sku}` (fallback: parent URL when SKU is null). edit_url = parent edit URL |
| isActive | bool | Parent product visibility |
| vatExclusive | bool | Parent tax treatment |
| vatRelief | bool | Parent VAT relief |
| hasFreeDelivery | bool | Parent delivery flag |
| freeDelivery | ?FreeDeliveryType | Parent delivery type enum |
| mainCategoryIds | list\<IntId\> | Parent main categories (no full categoryIds) |
| resolvedImage | ?ProductImage | From imageIndex + parent images. **Null when imageIndex is null — TBD: fallback to first parent image?** |

### Conditional includes (`?include=`)

| Include | Type | Notes |
|---------|------|-------|
| sale_settings | ?SaleSettings | Parent sale metadata (reason, dates, ends-stock) |
| inventory | ?ProductInventory | Variation Linnworks inventory |
| suppliers | list\<ProductSupplier\> | Variation supplier list |

## Decisions Made

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Standalone `GET /api/variations` (not per-product sub-resource) | Cross-catalog browsing without navigating to parent first |
| 2 | Self-contained rows with denormalized parent | Frontend table needs no second API call |
| 3 | Composition VO shape (holds ProductVariationView, not flattened) | No field duplication, no divergence risk, single source of truth |
| 4 | SQL view expansion for filterable parent fields | Single query handles filtering; mirrors products_view pattern |
| 5 | mainCategoryIds only (not full categoryIds) | Main categories sufficient for table filtering |
| 6 | saleSettings behind `?include=sale_settings` | Matches products endpoint pattern |
| 7 | Variation title = parent title + delimiter + option values | User-specified format (e.g., "Toilet Sign - Red 300mm Self-Adhesive") |
| 8 | No slug (redundant with links) | Public URL sufficient |
| 9 | No sortOrder (covered by popularity) | SKU-level popularity serves same purpose |
| 10 | No parent popularity (variation-level suffices) | Already on ProductVariationView |
| 11 | No hasAnySale (parent aggregate = noise) | Each variation has its own isOnSale |
| 12 | Fallback: no SKU → public_url = parent URL only | Legacy data degrades gracefully |
| 13 | Fallback: no options → variation title = parent title only | Legacy data degrades gracefully |
| 14 | Stock VO: expose both available + physical at top level | User choice, overriding existing pattern |

## Resolved TBDs

All originally-open questions have been resolved:

| # | Resolution |
|---|------------|
| 1 | resolvedImage = null when imageIndex is null (no fallback to parent image) |
| 2 | Title format: `{Product Title} - {option1} {option2} {optionN}` — single dash, space-separated options |
| 3 | Query params: mirror products endpoint (page, per_page, sort_by, sort_direction, main_category_id, is_on_sale, is_active, has_free_delivery) — but NO sku filter |
| 4 | Include SKU-less variations (they're errors but still shown) |
| 5 | Full Stock VO (available + physical) on new endpoint only. Existing ProductVariationResource stays as-is (availableStock int) |
| 6 | Add createdAt/updatedAt to existing ProductVariationView too (all consumers get timestamps) |

## ~~Open Questions (TBD)~~ — All resolved

| # | Question | Impact |
|---|----------|--------|
| 1 | resolvedImage fallback when imageIndex is null — return null or first parent image? | Resource shape |
| 2 | Variation title delimiter format (` - ` or ` / ` or other) | Display logic |
| 3 | Pagination/sorting/filtering query parameters (sort_by, per_page, category_id filter, etc.) | Controller/DTO design |
| 4 | Whether to filter out SKU-less variations at the SQL level or include them | SQL view definition |
| ~~5~~ | ~~M2 (Stock)~~ — RESOLVED: No. New endpoint exposes full Stock VO (available + physical). Existing ProductVariationResource stays as-is (availableStock int only). | — |
| 6 | Whether `createdAt`/`updatedAt` surfacing should also be done on the existing ProductVariationView (for nested use) or only on the new composition VO | Scope |

## Implementation Phases (Draft)

### Phase 1: Data Layer
- Expand `catalog.product_variations_view` SQL view with parent columns
- Add created_at/updated_at to ProductVariationView (or only to new VO — TBD #6)
- Create `VariationListItem` composition VO

### Phase 2: Assembler + Use Case
- Create `VariationListAssembler` (composes variation view models + parent context)
- Create `ListVariationsUseCase` (pagination, filtering, sorting)
- Create `ListVariationsQuery` DTO

### Phase 3: API Layer
- Create `VariationListResource` (API resource)
- Create `ListVariationsRequestDTO` (request validation)
- Add route `GET /api/variations`
- Wire controller method

### Phase 4: Tests
- Unit tests for VariationListItem VO (title computation, link generation, image resolution)
- Unit tests for VariationListAssembler
- Integration tests for endpoint (filtering, pagination, includes)

## Decision Log

### 2026-05-02 — Phase 1 (Data Layer) complete

- SQL view migration added: `database/migrations/2026_05_02_100001_expand_catalog_product_variations_view_with_parent_columns.php` (DROP + CREATE — views can't be ALTERed)
- `ProductVariationView` VO: `createdAt` / `updatedAt` added as **required** `DateTimeImmutable` constructor params (TBD #6 resolved: surfaced for all consumers, not just the new endpoint)
- `ProductVariationModelMapper` passes timestamps through
- `ProductVariationViewModel` extended with `parent_*` columns + casts
- All 1643 domain tests pass after updating constructor sites in: `StockTest`, `ProductVariationViewTest`, `ProductViewMetaTest`, `ProductViewTest` (6 sites), `ProductControllerTest` (3 sites), `ReconcileShopwiredComparePriceUseCaseTest`

### 2026-05-02 — Phase 1b (Domain VOs) complete

5 new domain types created:
- `VariationLinks` — readonly VO; `publicUrl` derived in constructor (`parentPublicUrl . '?var=' . urlencode(sku)` or just parent URL when SKU null)
- `VariationInclude` — backing enum: `SaleSettings`, `Inventory`, `Suppliers`
- `VariationSortField` — backing enum: `Price`, `EffectivePrice`, `Stock`, `ProfitMargin`, `CreatedAt`, `UpdatedAt`
- `VariationFilterField` — backing enum: `IsActive`, `CategoryId`, `IsOnSale`, `HasFreeDelivery`
- `VariationListItem` — composition VO holding `ProductVariationView $variation` + parent context. Static helpers: `buildTitle()`, `resolveImage()`

### 2026-05-02 — Phase 2 (Assembler + Use Case) — COMPLETE

- `VariationListQueryParams`, `VariationSortFieldMapper`, `VariationListAssembler` created
- `ProductRepositoryInterface::paginateVariations()` declared + implemented in `EloquentProductRepository`
- `ListVariationsUseCase` created (mirrors `ListProductsUseCase` pattern)
- `EloquentProductRepository::buildVariationScope()` + `variationRelationsForIncludes()` added

### 2026-05-02 — RESOLVED: `shipmonk.checkedExceptionInCallable` suppression

**Root cause**: `EloquentGateway::paginate()` had `@param-immediately-invoked-callable` on `$mapper` but NOT on `$scope`. Both closures execute synchronously.

**Fix**: Added `@param-immediately-invoked-callable` to the `$scope` parameter on `EloquentGateway::paginate()`. Removed the existing inline `@phpstan-ignore-next-line` from `buildScope()`. All call sites (4 repositories) benefit.

**Postmortem**: Applied corrective rule to repo CLAUDE.md — "Mandatory order before any suppression" with note that existing inline suppressions are not approval.

### 2026-05-02 — Phase 3 (API Layer) — COMPLETE

- `ListVariationsRequestDTO` — Spatie Data, mirrors `ListProductsRequestDTO` (no SKU filter)
- `VariationListResource` — serializes `VariationListItem` with `variationFields()` + `parentContextFields()` + `conditionalIncludes()`
- `VariationController` — single `index()` method, uses `BuildsPaginatedResponseTrait`
- Route: `GET /api/products/variations` wired in `routes/api.php`
- All 3337 tests pass (0 failures, 12 pre-existing notices)

### 2026-05-02 — Lint fixes (Step 5) — COMPLETE

4 categories of PHPStan errors fixed:
- **`checkedExceptionInCallable` (4 errors)**: Refactored `buildScope()`/`buildVariationScope()` to resolve `FilterField::from()` outside the closure, passing pre-resolved `[FilterField, value]` pairs into the closure. Also fixes pre-existing error on `buildScope()`.
- **`missingType.checkedException` (5 errors)**: Added `phpstan.neon` entry for `ListVariationsRequestDTO` (mirrors existing `ListProductsRequestDTO` entry). DTO `toQuery()` calls `::from()`/`::fromValue()` on pre-validated values.
- **`alz.excessiveMethodLength` (2 errors)**: Split `VariationListResource::variationFields()` into `identityAndPricingFields()` + remainder. Split `VariationListAssembler::toListItem()` into `toListItem()` + `buildListItem()` + `buildLinks()` + `resolveFreeDelivery()`.
- **`method.notFound` (1 error)**: `Stock::toArray()` doesn't exist (domain VO rule). Replaced with manual `['available_stock' => ..., 'physical_stock' => ...]` serialization in resource.
- Updated `phpstan-complexity-baseline.neon` line count for `EloquentProductRepository` (948→1022).

### 2026-05-02 — Simplify (Step 7) — COMPLETE

3 fixes applied from code reuse/quality/efficiency review:
- **Extracted `resolveDefaultSupplier`/`resolveSuppliers`** from both `VariationListAssembler` and `ProductViewAssembler` into `ProductVariationModelMapper` — eliminates verbatim duplication, both assemblers already hold a reference to the mapper.
- **Fixed double `mb_trim`** in `VariationListItem` constructor — was calling `mb_trim()` twice on `parentSkuRaw` (once for the empty check, once for `Sku::fromTrusted`). Now trims once into a local variable.
- **Removed dead `'stockItem'` relation guard** in `variationRelationsForIncludes()` — bare `'stockItem'` was redundant because `'stockItem.suppliers'` (always loaded) already loads the parent relation. Simplified to just return `['stockItem.suppliers']`.
- Updated `phpstan-complexity-baseline.neon` line count for `EloquentProductRepository` (1022→1008).

### 2026-05-02 — Sweep (Step 8) — COMPLETE

General-purpose subagent ran the `sweep` skill against the branch — no fixes required. All 5 linters pass, all 3337 tests pass.

### 2026-05-02 — Validation (Step 9) — COMPLETE

**Bug found and fixed during live validation:**
- Endpoint returned 500 on first call: `TypeError: $vatExclusive must be of type bool, null given`. Root cause: SQL view used `LEFT JOIN shopwired.products` for parent context columns even though `base_pricing` CTE already does `INNER JOIN shopwired.products` (so orphaned variations were already excluded from the result set anyway). The `LEFT JOIN` was redundant looseness allowing nulls in PHP that could never actually appear.
- **Fix**: Changed `LEFT JOIN shopwired.products p` → `INNER JOIN shopwired.products p` on the main query. Zero result-set change, makes intent explicit, guarantees non-null parent columns. One-line SQL fix.
- Re-ran migrations, tests still pass.

**Live endpoint validation (curl against local Octane):**
- Default `GET /api/variations`: 200, returns 3054 variations paginated
- Filter `is_active=1`: 200, returns 2636 active variations
- Filter `has_free_delivery=1`: 200, returns 20 variations with free delivery
- Filter `category_id=1`: 200
- Sort `sort_by=effective_price&sort_direction=desc`: 200, top result is most expensive variation
- Sort `sort_by=updated_at&sort_direction=desc`: 200, top result is most recent
- Includes `?include=sale_settings,suppliers,inventory`: 200, conditional fields correctly populated
- Invalid sort field: 422 validation error with proper message
- Boolean `?is_active=true`: 422 (Laravel's `boolean` rule requires `1`/`0`, not `true`/`false`) — matches products endpoint behaviour

### 2026-05-03 — Architectural Review (Post-Sweep)

4 items reviewed; 3 structural changes agreed:

**Q1 — Route nesting**: `GET /api/variations` → `GET /api/products/variations`. Variations are a sub-resource of the product catalog. Nesting under `/products` scopes the URL and avoids top-level namespace collision. Still a flat list (not per-product), just scoped by URL hierarchy.

**Q2 — Repository extraction (ISP)**: `EloquentProductRepository::paginateVariations()` + `buildVariationScope()` extracted to dedicated `VariationQueryRepositoryInterface` + `EloquentVariationQueryRepository`. The write-heavy `ProductRepositoryInterface` shouldn't own a read-only variation query. New repo uses `EloquentGateway` directly (no `AbstractEloquentRepository` — pure query path). Binding added to `CatalogServiceProvider` (not `ShopwiredServiceProvider`).

**Q3 — Mapper split**: Dual-purpose `ProductVariationModelMapper` (write + read) split into:
- `ProductVariationModelMapper` — write path: `toDomain()`, `toModelAttributes()`
- `ProductVariationViewModelMapper` — read path: `toViewDomain()`, `resolveDefaultSupplier()`, `resolveSuppliers()`

Both assemblers (`ProductViewAssembler`, `VariationListAssembler`) updated to reference the new view mapper.

**Q4 — Shared ViewModel**: Accepted as v1 cost. `ProductVariationViewModel` carries parent columns for both nested and standalone paths. If divergence becomes a maintenance burden, split into separate models later.

**phpstan.neon**: `buildVariationScope` suppression split into two separate `buildScope` entries (one per repository file).

### 2026-05-03 — View Slim-Down (Parent Column Audit) — IN PROGRESS

User challenged the 10 denormalized `parent_*` columns on the SQL view. After sequential-thinking analysis, agreed on a hybrid approach: eager-load parent `ProductModel` via Eloquent relationship for context data, keep only filter columns in the SQL view.

**Changes in progress:**
1. ✅ New migration: `2026_05_03_100001_slim_catalog_product_variations_view_parent_columns.php`
   - Removed 7 parent columns: `parent_title`, `parent_sku`, `parent_url`, `parent_vat_exclusive`, `parent_vat_relief`, `parent_images`, `parent_custom_fields`
   - Added computed `variation_title` (parent title + ' - ' + option value_names in SQL)
   - Kept 3 filter columns: `parent_is_active`, `parent_has_free_delivery`, `parent_main_category_ids`
2. ✅ `ProductVariationViewModel`: removed 7 parent properties/casts, added `variation_title`, added `product()` BelongsTo relationship to `ProductModel`
3. ✅ `EloquentVariationQueryRepository`: added `'product'` to eager-load relations
4. ✅ `VariationListAssembler`: refactored to use `$model->product->*` via eager-loaded parent + `Assert::isInstanceOf` guard
5. ✅ Move `ProductVariationViewModelMapper` binding: `ShopwiredServiceProvider` → `CatalogServiceProvider` (with `provides()` entry)
6. ✅ Migration, lint, tests — all 3337 tests pass, all 5 linters clean

**Key decisions:**
- `variation_title` computed in SQL (parent title + options already available in the view's JOIN) — eliminates `parent_title`
- `parent_url` cannot move to SQL (PHP `urlencode()` has no Postgres equivalent) — accessed via eager-loaded `$model->product->url`
- `parent_custom_fields` blob eliminated — SaleSettings + FreeDeliveryType resolved from `$model->product->custom_fields`
- `parent_images` eliminated — image resolution uses `$model->product->images` via eager-load
- Double-join concern (view already JOINs products for pricing, Eloquent JOINs again for parent model) is acceptable — Eloquent eager-loader batches into a single `WHERE id IN (...)` query

**Additional cleanup:**
- Removed dead `VariationListItem::buildTitle()` — title computation now handled by SQL `variation_title` column

## Status

All implementation phases complete. Ready for PR.

## Related Files

- `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php`
- `app/Domain/Catalog/Product/ValueObjects/ProductView.php`
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
- `app/Presentation/Http/Api/Resources/ProductVariationResource.php`
- `app/Presentation/Http/Api/Resources/ProductResource.php`
- `database/migrations/2026_04_18_024602_add_available_physical_stock_to_catalog_product_views.php` (latest view def)
