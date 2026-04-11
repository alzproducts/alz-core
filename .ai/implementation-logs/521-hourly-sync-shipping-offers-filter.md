# Implementation Log: #521 — Hourly sync for ShopWired Shipping Offers product filter from free-delivery custom field

## Issue Context
ShopWired product filter slot 20 ("Shipping Offers") is currently unmanaged. Products have a `free_delivery` custom field (`Standard`, `Express`, or empty) that should be reflected as filter values (`Free Standard Delivery` / `Free Express Delivery`) but no sync job exists to reconcile them hourly.

Custom-field → filter-value mapping:
- `Standard` → `Free Standard Delivery`
- `Express` → `Free Express Delivery`
- none/null/empty → slot cleared

Slot 20 is dedicated (no admin-maintained siblings), so no merge-preserving logic needed.

## Implementation

### Files Created
- `app/Domain/Catalog/Product/Enums/ShippingOffersFilterValue.php` — enum with `FreeStandardDelivery` / `FreeExpressDelivery` cases, jsonb parsing
- `database/migrations/2026_04_11_210000_create_catalog_products_with_changed_shipping_offers_filters_view.php` — SQL view using CASE on `free_delivery`, order-insensitive diff
- `app/Application/Contracts/Catalog/ShippingOffersFilterQueryRepositoryInterface.php` — interface with `getProductsWithChangedShippingOffersFilters()`
- `app/Infrastructure/Catalog/Repositories/ShippingOffersFilterQueryRepository.php` — queries the view, maps rows to DTOs
- `app/Application/Catalog/UseCases/SyncShippingOffersFiltersUseCase.php` — orchestrates: query → dispatch per product
- `app/Infrastructure/Jobs/Catalog/SyncShippingOffersFiltersJob.php` — hourly orchestrator, uniqueId = `sync-shipping-offers-filters`
- `tests/Unit/Application/Catalog/UseCases/SyncShippingOffersFiltersUseCaseTest.php` — 4 scenarios: empty, dispatch, filter-cleared, mixed-batch
- `tests/Integration/Catalog/ShippingOffersFilterGroupGuardTest.php` — asserts `external_id = 11411` maps to `option_no = 20`

### Files Edited
- `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php` — added `case ShippingOffers = 20`
- `app/Providers/CatalogServiceProvider.php` — registered `ShippingOffersFilterQueryRepositoryInterface` binding
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — added hourly `SyncShippingOffersFiltersJob` schedule entry

## Test Results

- Full suite: **2972 passed** (6859 assertions) — no regressions
- New unit tests pass: `SyncShippingOffersFiltersUseCaseTest` (4 scenarios)
- Guard test (`ShippingOffersFilterGroupGuardTest`) is an integration test requiring DB — not run locally without live DB populated with ShopWired filter groups

## Lint Results

- **Pint**: pass (no style changes)
- **PHPStan**: initially 1 error — `registerFilterQueryRepositories()` became 21 lines after adding 4th binding
  - Fixed by removing blank lines between bindings (no semantic change), reducing to 18 lines
- **PHPArkitect**: no violations
- **Deptrac**: no violations
- **TLint**: LGTM

All linters pass after fix.

## Handoff Notes

- Guard test (`ShippingOffersFilterGroupGuardTest`) verifies `external_id = 11411` maps to `option_no = 20`. Will fail if the filter group hasn't been synced from ShopWired — run the ShopWired filter-group sync first
- Migration creates `catalog.products_with_changed_shipping_offers_filters` view — run `php artisan migrate` before smoke testing
- Slot 20 is assumed dedicated (see plan assumption 1) — if admin-maintained values coexist, the view would need merge-preserving logic from the Offers migration pattern
