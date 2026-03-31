# Implementation Log: Issue #437 — Catalog Views & Query Infrastructure

## Status: In Progress

## Summary
Building PostgreSQL views (`catalog.products_view`, `catalog.product_variations_view`) that join Linnworks cost prices and compute derived columns (`profit_margin`, `effective_price`, `is_on_sale`). Introducing domain primitives for pagination/sorting/filtering, extending `ProductListQueryParams`, and wiring the repository to query the view instead of raw tables.

## Decisions

- Views use CTE pipeline pattern: `tax_config` → `pricing` (→ `base_pricing` for variations) → main SELECT
- `catalog` schema separates read-model views from source schemas (`shopwired`, `linnworks`)
- `ProductViewModel` / `ProductVariationViewModel` Eloquent models back the views (read-only)
- `ProductCostPriceFactory` removed from read path; cost_price flows from view join
- `PageRequest` goes in `Domain\Shared\Pagination\ValueObjects` (parallels `Money` in `Domain\Shared`)
- `ProductListQueryParams` breaking change: `$perPage/$page` → `PageRequest $pagination`

## Phases

- [x] Phase 1: Migrations (catalog schema + views)
- [x] Phase 2: Domain primitives (PageRequest, SortDirection)
- [x] Phase 3: Product domain enums (ProductSortField, ProductFilterField)
- [x] Phase 4: Extend ProductListQueryParams
- [x] Phase 5: Repository + Assembler + Variation mapper updates
- [x] Phase 6: Use case logging update
- [x] Phase 7: Presentation layer (ListProductsRequestDTO, ProductController, bump Max to 1000)
- [x] Phase 8: Update tests

## Progress Log

### 2026-03-31 — Setup
- Created branch `feature/437-catalog-views-query-infrastructure` from `origin/develop`
- Plan doc already exists at `.ai/plans/2026-03-31_437-catalog-views-query-infrastructure.md`

### 2026-03-31 — Implementation complete
- All 9 phases delivered. 2766 tests pass. All linters clean.
- `ProductCostPriceFactory` deleted — Linnworks cost price now flows from SQL view join
- Custom PHPStan rule `ShopwiredModelMustImplementMappableRule` updated to skip `ViewModel` classes
- Complexity baseline updated for line count changes in repository + service provider
- Simplify: fixed `relationsForIncludes()` inconsistency; moved `sort_direction` validation to enum-driven `rules()`
- Sweep: passed clean, no further changes

## PR Notes

(Draft PR description to be added before creating PR)
