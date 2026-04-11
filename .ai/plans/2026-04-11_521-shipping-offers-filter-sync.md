# Plan — Sync ShopWired "Shipping Offers" Filter from `free_delivery` Custom Field

## Context

We recently shipped two filter sync jobs (`SyncVatReliefFiltersJob` — #517, `SyncOffersFiltersJob` — #519) that hourly reconcile ShopWired product filters against a canonical rule expressed in a Postgres SQL view. Both use the same pattern: view → repository → UseCase → orchestrator job → per-product `UpdateProductFilterJob`.

This plan extends the pattern to a **new, dedicated** filter group:

| Field | Value |
|---|---|
| Filter title | `Shipping Offers` |
| External id (ShopWired) | `11411` |
| `option_no` (slot) | `20` |
| Source | `shopwired.products.custom_fields->>'free_delivery'` |

### Custom-field → filter-value mapping

| `free_delivery` value | Desired filter value |
|---|---|
| `Standard` | `Free Standard Delivery` |
| `Express`  | `Free Express Delivery` |
| `none` / `''` / `NULL` | *(empty — slot cleared)* |

### Why simpler than Offers
Slot 20 is a **new, dedicated** group (no admin-maintained siblings), so the SQL view does **not** need the merge-preserving strip/re-append logic the Offers view uses. The view is a straightforward `CASE`-based array builder, order-insensitively diffed against the current slot.

### Assumptions
1. Slot 20 is dedicated — no one else writes to it. If this is wrong the view needs the merge-preserving pattern from `2026_04_11_200000_create_catalog_products_with_changed_offers_filters_view.php`.
2. `shopwired.filter_groups` already contains (or will contain, via the existing filter-group sync) a row with `external_id = 11411`, `option_no = 20`. The guard test depends on it.
3. `free_delivery` is a parent-level custom field — variants do not carry their own `free_delivery`. No variant roll-up needed (unlike Offers, which inherits variant sale state).

---

## Files to Create

### 1. Domain enum — `app/Domain/Catalog/Product/Enums/ShippingOffersFilterValue.php`
- Mirror `OffersFilterValue` (jsonb-based, since values contain whitespace).
- Implements `App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface`.
- Cases:
  - `FreeStandardDelivery = 'Free Standard Delivery'`
  - `FreeExpressDelivery = 'Free Express Delivery'`
- Methods:
  - `fromString(string): self` — `tryFrom` + `InvalidEnumValueException::invalidBackingValue()`
  - `fromJsonArray(string $json): list<self>` — copy Offers implementation verbatim (decode + map).

### 2. Migration — `database/migrations/{ts}_create_catalog_products_with_changed_shipping_offers_filters_view.php`
Filename must include `catalog` (schema) per `database/CLAUDE.md`. View name: `catalog.products_with_changed_shipping_offers_filters`.

SQL (conceptual):
```sql
CREATE OR REPLACE VIEW catalog.products_with_changed_shipping_offers_filters AS
WITH desired AS (
    SELECT
        p.external_id AS product_id,
        COALESCE(p.filters->'20', '[]'::jsonb) AS slot20,
        CASE
            WHEN p.custom_fields->>'free_delivery' = 'Standard'
                THEN '["Free Standard Delivery"]'::jsonb
            WHEN p.custom_fields->>'free_delivery' = 'Express'
                THEN '["Free Express Delivery"]'::jsonb
            ELSE '[]'::jsonb
        END AS desired_filter_values
    FROM shopwired.products p
),
diff AS (
    SELECT
        product_id,
        COALESCE(
            (SELECT jsonb_agg(value ORDER BY value)
             FROM jsonb_array_elements_text(slot20) AS value),
            '[]'::jsonb
        ) AS current_sorted,
        desired_filter_values
    FROM desired
)
SELECT product_id, desired_filter_values
FROM diff
WHERE current_sorted IS DISTINCT FROM desired_filter_values;
```

Notes:
- `20` must be kept in sync with `FilterGroupOptionNo::ShippingOffers` — add a SQL comment.
- Order-insensitive diff on the current slot so storefront-reordered arrays don't produce spurious rows.
- `down()` drops the view.

### 3. Repository interface — `app/Application/Contracts/Catalog/ShippingOffersFilterQueryRepositoryInterface.php`
Copy `OffersFilterQueryRepositoryInterface` shape:
```php
interface ShippingOffersFilterQueryRepositoryInterface
{
    /** @return list<ProductFilterChangeDTO>
     *  @throws DatabaseOperationFailedException
     *  @throws DuplicateRecordException
     *  @throws ExternalServiceUnavailableException
     *  @throws InvalidEnumValueException
     */
    public function getProductsWithChangedShippingOffersFilters(): array;
}
```

### 4. Repository implementation — `app/Infrastructure/Catalog/Repositories/ShippingOffersFilterQueryRepository.php`
Mirror `OffersFilterQueryRepository.php:1-75`:
- `SELECT product_id, desired_filter_values FROM catalog.products_with_changed_shipping_offers_filters`
- Map rows → `ProductFilterChangeDTO` with `optionNo: FilterGroupOptionNo::ShippingOffers->value`
- Parse values via `ShippingOffersFilterValue::fromJsonArray()`.

### 5. Use case — `app/Application/Catalog/UseCases/SyncShippingOffersFiltersUseCase.php`
Direct copy of `SyncOffersFiltersUseCase.php:1-67` with:
- Dependency: `ShippingOffersFilterQueryRepositoryInterface`
- Log prefix: `SyncShippingOffersFilters:`
- Calls `$this->dispatcher->dispatchFilterUpdate(...)` per DTO (reuses existing `CatalogSyncDispatcherInterface` — **no new dispatcher method**; it's filter-agnostic).

### 6. Orchestrator job — `app/Infrastructure/Jobs/Catalog/SyncShippingOffersFiltersJob.php`
Direct copy of `SyncOffersFiltersJob.php:1-80` with:
- `uniqueId()` → `'sync-shipping-offers-filters'`
- Injects `SyncShippingOffersFiltersUseCase`
- Same `$tries`, `$timeout`, `$backoff`, `$uniqueFor`, `HandleDatabaseExceptions` middleware, `QueueName::Low`.

### 7. Tests
- **Unit** — `tests/Unit/Application/Catalog/UseCases/SyncShippingOffersFiltersUseCaseTest.php`
  Copy `SyncOffersFiltersUseCaseTest.php` (216 lines). Adjust: fake DTOs with `optionNo = 20`, enum cases `FreeStandardDelivery`/`FreeExpressDelivery`, log prefix. Cover: empty changes, successful dispatch, filter-cleared, mixed batch.
- **Guard** — `tests/Integration/Catalog/ShippingOffersFilterGroupGuardTest.php`
  Copy `OffersFilterGroupGuardTest.php:1-41`. Query `shopwired.filter_groups` where `external_id = 11411`, assert `option_no = FilterGroupOptionNo::ShippingOffers->value`.

---

## Files to Edit

### A. `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php`
Add case:
```php
case ShippingOffers = 20;
```

### B. `app/Providers/CatalogServiceProvider.php`
Register the new repository binding alongside `OffersFilterQueryRepositoryInterface` and `VatReliefFilterQueryRepositoryInterface`:
```php
$this->app->bind(
    ShippingOffersFilterQueryRepositoryInterface::class,
    ShippingOffersFilterQueryRepository::class,
);
```

### C. `app/Providers/Schedule/CatalogScheduleServiceProvider.php` (line ~82-89)
Add a fourth hourly entry mirroring the Offers schedule block:
```php
Schedule::job(new SyncShippingOffersFiltersJob())
    ->name('sync-shipping-offers-filters')
    ->hourly()
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(30);
```

---

## Existing Code to Reuse (no new versions of these)

| Reusable | Location |
|---|---|
| `ProductFilterChangeDTO` | `app/Application/Catalog/DTOs/ProductFilterChangeDTO.php` — multi-filter payload DTO |
| `CatalogSyncDispatcherInterface::dispatchFilterUpdate()` | `app/Application/Contracts/Catalog/` — filter-agnostic (accepts any `optionNo`) |
| `UpdateProductFilterJob` | `app/Infrastructure/Jobs/Catalog/UpdateProductFilterJob.php` — filter-agnostic per-product update |
| `ShopwiredFilterValueInterface` | `app/Domain/Catalog/Product/Contracts/ShopwiredFilterValueInterface.php` — marker on value enums |
| `HandleDatabaseExceptions` middleware | `app/Infrastructure/Jobs/Middleware/HandleDatabaseExceptions.php` |
| `EloquentGateway` | for typed query execution inside the new repository |

---

## Critical Files — For Reference During Implementation

- `app/Infrastructure/Jobs/Catalog/SyncOffersFiltersJob.php` — orchestrator template
- `app/Application/Catalog/UseCases/SyncOffersFiltersUseCase.php` — UseCase template
- `app/Infrastructure/Catalog/Repositories/OffersFilterQueryRepository.php` — repository template
- `app/Domain/Catalog/Product/Enums/OffersFilterValue.php` — enum template (jsonb parsing)
- `database/migrations/2026_04_11_200000_create_catalog_products_with_changed_offers_filters_view.php` — view template (but *drop* merge-preserving logic)
- `database/migrations/2026-04-03_000001_update_catalog_products_view_stock_from_linnworks.php:99-102` — canonical `free_delivery` custom-field access pattern
- `tests/Unit/Application/Catalog/UseCases/SyncOffersFiltersUseCaseTest.php` — test template
- `tests/Integration/Catalog/OffersFilterGroupGuardTest.php` — guard test template
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — schedule registration
- `app/Providers/CatalogServiceProvider.php` — repository binding
- `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php` — enum registration

---

## Verification

1. **Migrate**: `php artisan migrate` — confirms the new view compiles.
2. **Spot-check the view** via a read-only query against a local DB populated with ShopWired products:
   ```
   SELECT * FROM catalog.products_with_changed_shipping_offers_filters LIMIT 5;
   ```
   Rows should reflect products whose slot 20 disagrees with their `free_delivery` custom field.
3. **Linters**: stop hook runs `make lint` (Pint, PHPStan max, PHPArkitect, Deptrac, TLint) — no new entries in the PHPStan complexity baseline (per `feedback_baseline_rule`).
4. **Unit test**:
   ```
   make test -- --filter=SyncShippingOffersFiltersUseCaseTest
   ```
5. **Guard test** (requires DB with filter_groups populated):
   ```
   make test -- --filter=ShippingOffersFilterGroupGuardTest
   ```
6. **Smoke test locally** via tinker (never prod):
   ```
   php artisan tinker --execute="\App\Infrastructure\Jobs\Catalog\SyncShippingOffersFiltersJob::dispatch();"
   ```
   Tail `storage/logs/laravel.log` for `SyncShippingOffersFilters: starting` → `dispatched ... updates` and confirm `UpdateProductFilterJob` instances are enqueued on the `bulk` queue.
7. **Full check**: `make check` (lint-full + tests) before PR.

---

## Open Questions (flag if discovered during implementation, don't silently decide)

- **Slot 20 ownership**: if it turns out the slot is shared with admin-maintained values (unlikely for a brand-new filter group but worth verifying against the first view output), promote the view to the merge-preserving pattern used in the Offers migration.
- **Filter group seeding**: if the guard test fails because `shopwired.filter_groups` has no row for `external_id = 11411`, we need to trigger the existing ShopWired filter-group sync (or add a one-off seed) *before* enabling the schedule.
