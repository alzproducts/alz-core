# Issue #596 — Migrate remaining read sites to View + Assembler pattern

**Branch:** `feature/596-migrate-read-sites-view-assembler`
**Plan:** `.ai/plans/2026-04-18_596-migrate-remaining-read-sites-view-assembler.md`
**Status:** Complete
**Started:** 2026-04-18
**Completed:** 2026-04-18

## Objective

Migrate 9 read sites (7 mechanical + Reconcile + CheckExpired) from write-side VOs (`Product`, `Category`) to the View + Assembler pattern. Run a dead-code sweep to actually shrink the write-side surface.

## Decision log

- 2026-04-18 — Branch `feature/596-migrate-read-sites-view-assembler` created from `origin/develop`.
- 2026-04-18 — Implemented as 5 bisectable commit-groups so each commit is `make lint && make test` green on its own.
- 2026-04-18 — Mid-flight scope additions:
  - Dropped sort-order side-effects from add/remove sale workflows (sort order no longer coupled to sale state) — landed in `64083ea6` between commit-groups 2 and 3.
  - Added `findDetailedProductView(IntId, list<ProductInclude>)` helper on `ProductRepositoryInterface` to collapse `findProductView(new ProductDetailQueryParams(...))` boilerplate across 8 call sites — landed in `2a3c20ae`.
- 2026-04-18 — Kept `Product::allOnSaleSkus`, `Product::$rawCustomFields`, `Product::$url`, `Product::totalStock/isInStock/getStockLevel`, and `SaleSettings::fromRawCustomFields` — each still has live callers outside the migrated paths.

## Commits

| SHA | Subject | Commit-group |
|-----|---------|--------------|
| `94d81b71` | `refactor(catalog): introduce shared Stock VO for available and physical stock` | 1 (scaffolding) |
| `8bd2cd56` | `refactor(catalog): swap 7 read sites to ProductView + propagate IntId through dispatcher chain` | 2 |
| `64083ea6` | `refactor(sales): drop sort-order side-effects from add/remove sale workflows` | interrupt |
| `2a3c20ae` | `refactor(catalog): introduce findDetailedProductView helper on ProductRepositoryInterface` | interrupt |
| `f798e2d1` | `refactor(sales): migrate reconcile-sale-state flow from Product to ProductView` | 3 |
| `06563800` | `refactor(sales): migrate check-expired-sales flow from Product to ProductView` | 4 |
| `bb9cb4db` | `refactor(catalog): drop write-side read helpers now unused after view migration` | 5 |

## Deviations from plan

- Plan listed scaffolding additions as commit-group 1; the Stock VO work delivered that plus a broader shared-stock refactor. Subsequent commits built on the Stock VO surface (`ProductView::$stockLevel->availableStock` replaced the planned `ProductView::totalStock()`).
- Two mid-flight commits were folded in (`64083ea6`, `2a3c20ae`) — both surfaced during migration and were cheaper to land inline than defer.
- `CategoryRepository::findByExternalId` was a cleaner sweep than the plan suggested: zero callers after the mechanical swap, so both the interface method and the Eloquent impl were deleted (plus the brand-repo twin).
- `SaleSettings::fromRawCustomFields` is still alive (used by `ProductViewAssembler:196` to build settings from the raw custom_fields column) — sweep noted but skipped.

## Final Lint / Test results

- `make lint` — green (Pint / PHPStan / PHPArkitect / Deptrac / TLint)
- `make test` — 3041 passed (7 ProductTest cases dropped as the corresponding Product methods were removed)

## PR Notes

### What

Migrate the remaining 9 read sites on the Shopwired sale-management path from write-side `Product`/`Category` VOs to the view-side `ProductView`/`CategoryView` projections. Extract the dead write-side surface that this migration frees up.

### Why

PR #594 landed the View + Assembler pattern but several read sites still loaded write-side `Product`/`Category` VOs with all their write-path machinery. This migration:

- narrows `Product` to the write paths that actually need it (webhook ingestion, pricing updates, API sync),
- lets the write-side VOs shed helpers that were only needed because read paths reused them,
- propagates `IntId` through dispatcher interfaces and command objects for the swapped paths.

### Key changes

**Scaffolding (commit-group 1):**
- Shared `Stock` VO (`availableStock` + `physicalStock`) replaces the ad-hoc int fields on `ProductView`/`ProductVariationView`.
- `ProductRepositoryInterface::findProductViewsOnSale()` added for the CheckExpired path.
- `SaleSettings::fromTypedCustomFields()` added for the Reconcile fallback path.

**Mechanical swaps (commit-group 2):**
7 read sites switched to `findDetailedProductView`/`findProductViewForApi`/`findCategoryForApi`. `IntId` propagated through `SaleReconciliationDispatcherInterface`, `UpdateProductCategoryMembershipCommand`, and the Slack listener payload.

**Resolver + UseCase rewrites (commit-groups 3 and 4):**
- `ProductSaleStateResolver::evaluate(Product)` → `evaluate(ProductView)` — reads `$view->hasAnySale` (pre-computed) instead of recomputing from `salePrice`/`variations`.
- `ReconcileProductSaleStateUseCase` — falls back to `SaleSettings::fromTypedCustomFields($view->customFields)` when the DB row is missing (legacy products that were added to sale before DB persistence was a thing).
- `CheckExpiredSalesUseCase` — loops `ProductView`s, reads `$view->stockLevel->availableStock`, keeps the same 4-condition removal matrix.

**Dead-code sweep (commit-group 5):**
- Deleted from `Product`: `isInCategory`, `getCustomField`, `hasCustomField`, `hasAnySaleActive`, `hasAnySaleCustomField`.
- Deleted from `CategoryRepositoryInterface` + `EloquentCategoryRepository`: `findByExternalId`.
- Deleted from `BrandRepositoryInterface` + `EloquentBrandRepository`: `findByExternalId`.

### Testing

Every commit is individually `make lint && make test` green — the PR is bisectable. Final stats: 3041 passing tests, clean Pint/PHPStan-max/PHPArkitect/Deptrac/TLint.
