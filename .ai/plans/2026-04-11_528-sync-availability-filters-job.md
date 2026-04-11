# SyncAvailabilityFiltersJob — "Hide Unavailable Items" filter slot

## Context

ShopWired filter groups don't support negative/exclusion filters, so "Hide Unavailable Items" is implemented inversely: we tag every **available** product with a single `Available` value in slot `option_no = 11` (filter group `external_id = 11413`, titled "Availability"), and the storefront JS aliases the customer-facing label "Hide Unavailable Items" → `Available` before submitting the filter query. This plan adds the backend sync that keeps slot 11 in lockstep with the availability rule by following the existing, filter-agnostic vertical slice that already powers `Offers`, `VatRelief`, `ShippingOffers`, `ShippingOptions`, and `CustomerRating` filters.

Because the rule depends on live stock (which syncs from Linnworks every 10 minutes) plus a handful of admin-managed custom fields (`preorder_date`, `preorder_disable`, `preorder_hide`, `discontinued`), we mirror the `ShippingOptions` cadence (every 10 min, offset 5 min after stock sync) rather than the hourly cadence used by the Offers/VatRelief jobs.

## Availability rule (to be encoded in the Postgres view)

Definitions:
```
has_stock          := products.stock > 0 OR EXISTS(variation with stock > 0)
is_active_preorder := jsonb_typeof(custom_fields->'preorder_date') = 'number'
                      AND preorder_disable is not true   -- false or missing → "not disabled"
                      AND preorder_hide    is not true   -- false or missing → "not hidden"
is_discontinued    := custom_fields->>'discontinued' IS NOT NULL
                      AND custom_fields->>'discontinued' != ''
```

Rule (locked in per user decisions; discontinued/preorder only apply when OOS):
```
Available ⇔ has_stock  OR  (NOT is_active_preorder AND NOT is_discontinued)
```

Outcome matrix:

| stock | active_preorder | discontinued | result |
|---|---|---|---|
| ✓ | — | — | **Available** |
| ✗ | ✗ | ✗ | **Available** (temporarily OOS — part of range) |
| ✗ | ✓ | — | Unavailable (can't buy now, coming soon) |
| ✗ | — | ✓ | Unavailable (permanently gone) |

This genuinely differs from `ShippingOptions`, which is pure `has_stock`.

### Live DB evidence that shaped the SQL predicates

| Field | JSONB type | Sample values | Presence test |
|---|---|---|---|
| `preorder_date` | `number` (Unix epoch seconds), or `null`, or absent | `1778245447`, `1734480000`, JSONB `null` | `jsonb_typeof(custom_fields->'preorder_date') = 'number'` |
| `preorder_disable` | `boolean` (strict, ShopWired honours its toggle type here) | `true`, `false` | `coalesce((custom_fields->>'preorder_disable')::boolean, false)` |
| `preorder_hide` | `boolean` | `true`, `false` | same pattern |
| `discontinued` | `string` | `"Discontinued by Manufacturer"`, `"Discontinued by AlzProducts"`, `"No Stock Long Term"`, `"Until stock delivered"`, `"Other"`, `""` | `custom_fields->>'discontinued' IS NOT NULL AND custom_fields->>'discontinued' != ''` |

Note: `preorder_disable` / `preorder_hide` are the **only** ShopWired custom fields in this codebase that are actually stored as JSONB booleans — every other custom field is string-ish. This is unusual enough to be worth a code comment in the view migration.

## Files to create (mirroring the ShippingOptions slice verbatim)

1. **Enum case** — `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php`
   - Add `case Availability = 11;` (11 is free — existing cases: VatRelief=2, Offers=14, CustomerRating=15, ShippingOffers=20, ShippingOptions=25)
   - Update the docblock listing the guard tests.

2. **Domain filter-value enum** — `app/Domain/Catalog/Product/Enums/AvailabilityFilterValue.php`
   - `: string implements ShopwiredFilterValueInterface`
   - `case Available = 'Available';`
   - `fromString(string): self` throwing `InvalidEnumValueException`
   - `fromJsonArray(string $json): list<self>`
   - Model after `ShippingOptionsFilterValue.php` (single-value enum, same shape).

3. **Repository interface** — `app/Application/Contracts/Catalog/AvailabilityFilterQueryRepositoryInterface.php`
   - `getProductsWithChangedAvailabilityFilters(): list<ProductFilterChangeDTO>`
   - Declare `@throws DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`, `InvalidEnumValueException` (identical to the other four interfaces).

4. **Repository implementation** — `app/Infrastructure/Catalog/Repositories/AvailabilityFilterQueryRepository.php`
   - Thin wrapper around `EloquentGateway::query()` executing `SELECT product_id, desired_filter_values FROM catalog.products_with_changed_availability_filters`.
   - `mapRowsToDtos($rows, FilterGroupOptionNo::Availability->value)` using `AvailabilityFilterValue::fromJsonArray(...)`.
   - Model exactly on `OffersFilterQueryRepository`.

5. **UseCase** — `app/Application/Catalog/UseCases/SyncAvailabilityFiltersUseCase.php`
   - `final readonly` with deps: `AvailabilityFilterQueryRepositoryInterface`, `CatalogSyncDispatcherInterface`, `LoggerInterface`.
   - `execute()`: log start → fetch changes → early-return-with-log on empty → loop and `$dispatcher->dispatchFilterUpdate($change->productId, $change->optionNo, $change->filterValuesForDispatch())`.
   - Copy shape from `SyncOffersFiltersUseCase.php:37-54`.

6. **Job** — `app/Infrastructure/Jobs/Catalog/SyncAvailabilityFiltersJob.php`
   - Mirror `SyncShippingOptionsFiltersJob.php` exactly (tight-cadence variant):
     - `$tries = 3`, `$maxExceptions = 2`, `$timeout = 120`, `$uniqueFor = 600`.
     - `retryUntil()` = `now()->addMinutes(9)`.
     - `uniqueId()` returns `'sync-availability-filters'`.
     - `onQueue(QueueName::Low->value)` in constructor.
     - `middleware() => [new HandleDatabaseExceptions()]`.
     - `handle(SyncAvailabilityFiltersUseCase $useCase): void` calling `$useCase->execute()`.

7. **Postgres view migration** — `database/migrations/{YYYY_MM_DD_HHMMSS}_create_catalog_products_with_changed_availability_filters_view.php`
   - Dedicated-slot pattern (copy `2026_04_11_220000_create_catalog_products_with_changed_shipping_options_filters_view.php`).
   - Core CTE body:
     ```sql
     WITH product_state AS (
         SELECT
             p.external_id AS product_id,
             COALESCE(p.filters->'11', '[]'::jsonb) AS slot11,
             (
                 (p.stock IS NOT NULL AND p.stock > 0)
                 OR EXISTS (
                     SELECT 1 FROM shopwired.product_variations v
                     WHERE v.product_external_id = p.external_id
                       AND v.stock > 0
                 )
             ) AS has_stock,
             (
                 p.custom_fields->>'discontinued' IS NOT NULL
                 AND p.custom_fields->>'discontinued' != ''
             ) AS is_discontinued,
             (
                 jsonb_typeof(p.custom_fields->'preorder_date') = 'number'
                 AND COALESCE((p.custom_fields->>'preorder_disable')::boolean, false) IS NOT TRUE
                 AND COALESCE((p.custom_fields->>'preorder_hide')::boolean,    false) IS NOT TRUE
             ) AS is_active_preorder
         FROM shopwired.products p
     ),
     desired AS (
         SELECT
             product_id,
             slot11,
             CASE
                 WHEN has_stock
                      OR (NOT is_active_preorder AND NOT is_discontinued)
                 THEN '["Available"]'::jsonb
                 ELSE '[]'::jsonb
             END AS desired_filter_values
         FROM product_state
     ),
     diff AS (
         SELECT
             product_id,
             COALESCE(
                 (SELECT jsonb_agg(value ORDER BY value) FROM jsonb_array_elements_text(slot11) AS value),
                 '[]'::jsonb
             ) AS current_sorted,
             desired_filter_values
         FROM desired
     )
     SELECT product_id, desired_filter_values
     FROM diff
     WHERE current_sorted IS DISTINCT FROM desired_filter_values
     ```
   - `down()` drops the view.
   - Header docblock must mention: (1) the rule, (2) that `preorder_disable`/`preorder_hide` are real JSONB booleans, (3) that any new product states (backorder, etc.) need an explicit decision on whether they earn the `Available` tag.

8. **Schedule registration** — `app/Providers/Schedule/CatalogScheduleServiceProvider.php`
   - Add a `registerAvailabilityFilterSchedule()` private method, mirroring `registerShippingOptionsFilterSchedule()`:
     ```php
     Schedule::job(new SyncAvailabilityFiltersJob())
         ->name('sync-availability-filters')
         ->cron('5-59/10 * * * *')
         ->timezone('Europe/London')
         ->onOneServer()
         ->withoutOverlapping(10);
     ```
   - Call it from `boot()`.

9. **Service provider binding** — `app/Providers/CatalogServiceProvider.php`
   - `scoped(AvailabilityFilterQueryRepositoryInterface::class, AvailabilityFilterQueryRepository::class)`
   - Add to `provides()`.

10. **Unit test** — `tests/Unit/Application/Catalog/UseCases/SyncAvailabilityFiltersUseCaseTest.php`
    - Copy `SyncShippingOptionsFiltersUseCaseTest.php` (single-value filter test shape).
    - Three cases: empty / happy-path dispatches / clears slot when no products match.

11. **Integration guard test** — `tests/Integration/Catalog/AvailabilityFilterGroupGuardTest.php`
    - `#[Group('integration')] #[CoversNothing]`.
    - Assert `shopwired.filter_groups` has a row with `external_id = 11413`, `option_no = 11`, and matching `FilterGroupOptionNo::Availability->value`.
    - Purpose: pre-push guard so a ShopWired renumbering fails loudly.

## Files NOT to change (already filter-agnostic)

- `app/Infrastructure/Jobs/Catalog/UpdateProductFilterJob.php` — operates on any `(productId, optionNo, filterValues)` tuple.
- `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php` + `QueuedCatalogSyncDispatcher.php` — already polymorphic over `ShopwiredFilterValueInterface`.
- `app/Application/Catalog/DTOs/ProductFilterChangeDTO.php` — accepts any `ShopwiredFilterValueInterface & BackedEnum`.
- `ProductUpdateClient::updateFilters()` — single-field update already handles this via merge.

## Verification plan

1. **Lint**: `make lint` — PHPArkitect enforces the `*Job` / `*UseCase` / `*Repository` naming; Deptrac enforces the layer boundaries. Both must pass unchanged.
2. **Unit tests**: `make test-quick` — runs the new `SyncAvailabilityFiltersUseCaseTest`.
3. **Integration guard**: `make test` — runs `AvailabilityFilterGroupGuardTest`. Confirms the `filter_groups` seed row is present (must be synced into local DB beforehand via the existing `SyncFilterGroupsUseCase`; if 11413 isn't there yet, run that sync first).
4. **Smoke test the view directly**:
   ```bash
   php artisan migrate
   php artisan tinker --execute="dump(DB::connection('pgsql')->select('SELECT product_id, desired_filter_values FROM catalog.products_with_changed_availability_filters LIMIT 10'));"
   ```
5. **Smoke test the job**:
   ```bash
   php artisan tinker --execute="App\Infrastructure\Jobs\Catalog\SyncAvailabilityFiltersJob::dispatch();"
   ```
   Check `storage/logs/laravel.log` for `"SyncAvailabilityFilters: starting"` and `"dispatched Availability filter updates"`.
6. **Spot-check known cases**:
   - OOS + discontinued → `desired = []`
   - OOS + active preorder → `desired = []`
   - OOS, no flags → `desired = ["Available"]` (temporarily OOS, part of range)
   - In-stock + discontinued → `desired = ["Available"]` (selling down)
   - OOS + preorder_disable=true → `desired = ["Available"]` (preorder disabled, falls through to temporarily-OOS)

## Critical files to re-read when implementing

- `app/Infrastructure/Jobs/Catalog/SyncShippingOptionsFiltersJob.php` — job template (tight-cadence variant)
- `app/Application/Catalog/UseCases/SyncOffersFiltersUseCase.php:37-54` — UseCase template
- `database/migrations/2026_04_11_220000_create_catalog_products_with_changed_shipping_options_filters_view.php` — view template (dedicated-slot pattern)
- `app/Domain/Catalog/Product/Enums/ShippingOptionsFilterValue.php` — single-value enum template
- `app/Infrastructure/Catalog/Repositories/OffersFilterQueryRepository.php` — repository template
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php:125-135` — schedule template (10-min cron)
- `app/Providers/CatalogServiceProvider.php:61-87` — binding template
- `tests/Integration/Catalog/ShippingOptionsFilterGroupGuardTest.php` — guard-test template
