# Plan — Sync ShopWired "Shipping Options" Filter from Stock Availability

## Context

We have three existing ShopWired filter-sync jobs (`SyncVatReliefFiltersJob` #517, `SyncOffersFiltersJob` #519, and the in-flight `SyncShippingOffersFiltersJob` for `free_delivery`). All three follow the same shape: SQL view → repository → UseCase → orchestrator job → per-product `UpdateProductFilterJob`, scheduled hourly.

This plan adds a **fourth, independent** filter group — confusingly named very close to the WIP one, but a completely separate ShopWired filter:

| Field            | Value                                                            |
|------------------|------------------------------------------------------------------|
| Filter title     | `Shipping Options` (distinct from WIP `Shipping Offers`)         |
| External id      | `11412`                                                          |
| `option_no` slot | `25`                                                             |
| Sort order       | `1`                                                              |
| Source           | ShopWired product + variation stock columns (ignore Linnworks)   |
| Cadence          | **Every 10 minutes** (not hourly — stock changes faster)         |

### Rule

| Stock state                                       | Desired slot 25 value            |
|---------------------------------------------------|----------------------------------|
| Parent `stock > 0`, OR any variation `stock > 0`  | `["Next Day Delivery Available"]`|
| Parent stock is null/≤0 AND no variation has stock| `[]` (clear slot)                |

### Why the stock predicate needs care

`shopwired.products.stock` is **nullable** (null for products that have variations — `database/migrations/2026_01_17_050000_create_shopwired_products_table.php:41`). `shopwired.product_variations.stock` is **not-null** (`…_050001…:48`). Variants join via `product_variations.product_external_id = products.external_id`. The view must null-guard the parent check so `NULL > 0` (UNKNOWN) doesn't silently drop in-stock rows.

---

## Files to Create

### 1. Domain enum — `app/Domain/Catalog/Product/Enums/ShippingOptionsFilterValue.php`
Mirror `ShippingOffersFilterValue` exactly. Implements `ShopwiredFilterValueInterface`. Backed string enum:
```php
case NextDayDeliveryAvailable = 'Next Day Delivery Available';
```
Methods `fromString()` and `fromJsonArray()` copied verbatim (jsonb decode + map).

### 2. Migration — `database/migrations/{ts}_create_catalog_products_with_changed_shipping_options_filters_view.php`

View: `catalog.products_with_changed_shipping_options_filters`. Slot 25 is confirmed **dedicated** (no admin-maintained siblings), so this uses the simpler CASE-based pattern from the ShippingOffers WIP view — no strip/re-append. The only novelty versus ShippingOffers is the stock predicate that joins products with variations and null-guards parent stock.

```sql
CREATE OR REPLACE VIEW catalog.products_with_changed_shipping_options_filters AS
WITH product_stock_state AS (
    SELECT
        p.external_id AS product_id,
        COALESCE(p.filters->'25', '[]'::jsonb) AS slot25,
        (
            (p.stock IS NOT NULL AND p.stock > 0)
            OR EXISTS (
                SELECT 1 FROM shopwired.product_variations v
                WHERE v.product_external_id = p.external_id
                  AND v.stock > 0
            )
        ) AS is_in_stock
    FROM shopwired.products p
),
desired AS (
    SELECT
        pss.product_id,
        pss.slot25,
        CASE
            WHEN pss.is_in_stock THEN '["Next Day Delivery Available"]'::jsonb
            ELSE '[]'::jsonb
        END AS desired_filter_values
    FROM product_stock_state pss
),
diff AS (
    SELECT
        product_id,
        COALESCE(
            (SELECT jsonb_agg(value ORDER BY value)
             FROM jsonb_array_elements_text(slot25) AS value),
            '[]'::jsonb
        ) AS current_sorted,
        desired_filter_values
    FROM desired
)
SELECT product_id, desired_filter_values
FROM diff
WHERE current_sorted IS DISTINCT FROM desired_filter_values;
```

- Add SQL comment pointing at `FilterGroupOptionNo::ShippingOptions` and `ShippingOptionsFilterValue::NextDayDeliveryAvailable` so a rename hits both places.
- Add SQL comment noting that `shopwired.products.stock` and `product_variations.stock` are **Linnworks-mirrored** (written by `SyncFullStockToShopwiredJob` / `SyncDeltaStockToShopwiredJob`) so future readers investigating stale data know where to look upstream.
- Order-insensitive diff on the current slot so storefront-reordered arrays don't produce spurious rows.
- `down()` drops the view.

### 3. Repository interface — `app/Application/Contracts/Catalog/ShippingOptionsFilterQueryRepositoryInterface.php`
Copy the ShippingOffers interface. Declares:
```php
/** @return list<ProductFilterChangeDTO>
 *  @throws DatabaseOperationFailedException
 *  @throws DuplicateRecordException
 *  @throws ExternalServiceUnavailableException
 *  @throws InvalidEnumValueException
 */
public function getProductsWithChangedShippingOptionsFilters(): array;
```

### 4. Repository implementation — `app/Infrastructure/Catalog/Repositories/ShippingOptionsFilterQueryRepository.php`
Mirror `ShippingOffersFilterQueryRepository`:
- `SELECT product_id, desired_filter_values FROM catalog.products_with_changed_shipping_options_filters`
- Map rows → `ProductFilterChangeDTO` with `optionNo: FilterGroupOptionNo::ShippingOptions->value`
- Parse values via `ShippingOptionsFilterValue::fromJsonArray()`
- Reuse `EloquentGateway` + existing `mapRowsToDtos()` pattern.

### 5. Use case — `app/Application/Catalog/UseCases/SyncShippingOptionsFiltersUseCase.php`
Direct copy of `SyncShippingOffersFiltersUseCase` with:
- Dependency: `ShippingOptionsFilterQueryRepositoryInterface`
- Log prefix: `SyncShippingOptionsFilters:`
- Reuses `CatalogSyncDispatcherInterface::dispatchFilterUpdate(IntId, int, ?array)` — filter-agnostic, no new dispatcher method.

### 6. Orchestrator job — `app/Infrastructure/Jobs/Catalog/SyncShippingOptionsFiltersJob.php`
Direct copy of `SyncShippingOffersFiltersJob.php` with:
- `uniqueId()` → `'sync-shipping-options-filters'`
- Injects `SyncShippingOptionsFiltersUseCase`
- `HandleDatabaseExceptions` middleware, `QueueName::Low` (unchanged from siblings)
- `$timeout = 120`, `$failOnTimeout = true`, `$backoff = [30, 60]` (unchanged)
- **`$tries = 3`** (not 4 like hourly siblings). With `$backoff = [30, 60]` and `$timeout = 120`, attempts run at t=0, t=30, t=90 and all finish by ~330s — comfortably inside the 9-min `retryUntil`. No hidden guillotine on attempt 4.
- **`$uniqueFor = 600`** (not 1200 like hourly siblings — 10-minute cadence requires TTL ≤ cadence). Add inline comment: *"Tighter TTL than hourly siblings because this runs every 10 minutes."*
- **`retryUntil()` returns `now()->addMinutes(9)`** (not 45) so a failed run can't bleed into the next tick.

### 7. Tests
- **Unit** — `tests/Unit/Application/Catalog/UseCases/SyncShippingOptionsFiltersUseCaseTest.php`
  Mirror `SyncShippingOffersFiltersUseCaseTest.php`. Adjust:
  - DTOs with `optionNo = 25`
  - Enum case `NextDayDeliveryAvailable`
  - Log prefix `SyncShippingOptionsFilters:`
  - Cover: empty changes, successful dispatch, filter-cleared (out-of-stock → null values), mixed batch.
- **Guard** — `tests/Integration/Catalog/ShippingOptionsFilterGroupGuardTest.php`
  Mirror `ShippingOffersFilterGroupGuardTest.php` exactly. Query `shopwired.filter_groups` where `external_id = 11412`; assert only:
  - `$row` is not null (loud failure if the filter group is missing)
  - `(int) $row->option_no === FilterGroupOptionNo::ShippingOptions->value` (25)

  Do **not** assert `title` or `sort_order`. Both existing guard tests (`OffersFilterGroupGuardTest:21-22`, `ShippingOffersFilterGroupGuardTest:22-23`) explicitly document: *"The filter title is NOT asserted verbatim because it is admin-editable in ShopWired. Identification must use the stable IDs instead."* — same reasoning applies to sort_order (admins reorder groups in the UI). Test method name: `shipping_options_filter_group_exists_with_external_id_11412_and_option_no_25`.

---

## Files to Edit

### A. `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php`
Add case (keeping numeric ordering):
```php
case ShippingOptions = 25;
```
Update the class docblock that lists guard tests to include `ShippingOptionsFilterGroupGuardTest`.

### B. `app/Providers/CatalogServiceProvider.php`
Register the new binding alongside VatRelief/Offers/ShippingOffers:
```php
$this->app->bind(
    ShippingOptionsFilterQueryRepositoryInterface::class,
    ShippingOptionsFilterQueryRepository::class,
);
```

### C. `app/Providers/Schedule/CatalogScheduleServiceProvider.php`
Add a new private `registerShippingOptionsFilterSchedule()` method (matching the existing per-sync method pattern) and call it from `boot()`:
```php
Schedule::job(new SyncShippingOptionsFiltersJob())
    ->name('sync-shipping-options-filters')
    ->cron('5-59/10 * * * *') // HH:05, HH:15, HH:25, ... — offset 5 min after SyncFullStockToShopwiredJob
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(10);
```

Why `cron('5-59/10 * * * *')` instead of `->everyTenMinutes()`: `SyncFullStockToShopwiredJob` (in `InventoryScheduleServiceProvider:41-45`) also uses `->everyTenMinutes()`, which aligns it to HH:00, HH:10, HH:20, … . That job **writes** `shopwired.products.stock` as the Linnworks→Shopwired mirror; our job **reads** the same column. Aligning to the same boundary guarantees a race every tick. Offsetting ours to HH:05 gives the stock sync a ~5-minute head start so we read a freshly-mirrored value.

Grace = 10 (matches cadence, mirrors `SyncFullStockToShopwiredJob` in `InventoryScheduleServiceProvider:43`).

**Private method docblock** — match the sibling style (3–4 lines summarising source → target), explicitly mention the 10-min cadence, the cron offset, and the upstream stock-sync dependency.

**Class docblock update** — the current docblock (lines 15-23) opens with *"Hourly syncs mapping product-level state to ShopWired product filters"*. With this change, rewrite to *"Product-level state syncs to ShopWired product filters (three hourly + one 10-minute stock-driven)"* and add a bullet: *"Shipping Options filter (from shopwired product + variation stock; 10-min cadence, offset +5 min from the upstream stock sync)"*.

---

## Existing Code to Reuse (do not duplicate)

| Reusable                                                     | Location                                                                                    |
|--------------------------------------------------------------|---------------------------------------------------------------------------------------------|
| `ProductFilterChangeDTO` (generic multi-filter payload)      | `app/Application/Catalog/DTOs/ProductFilterChangeDTO.php`                                   |
| `CatalogSyncDispatcherInterface::dispatchFilterUpdate()`     | `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php:18` — filter-agnostic |
| `UpdateProductFilterJob` (per-product update, filter-agnostic)| `app/Infrastructure/Jobs/Catalog/UpdateProductFilterJob.php`                                |
| `ShopwiredFilterValueInterface` (marker for value enums)      | `app/Domain/Catalog/Product/Contracts/ShopwiredFilterValueInterface.php`                    |
| `HandleDatabaseExceptions` middleware                         | `app/Infrastructure/Jobs/Middleware/HandleDatabaseExceptions.php`                           |
| `EloquentGateway` (typed query execution)                     | used inside every `*FilterQueryRepository`                                                  |
| Row→DTO mapper `mapRowsToDtos()`                              | existing private method pattern in `OffersFilterQueryRepository` / `ShippingOffersFilterQueryRepository` |

---

## Critical Files — For Reference During Implementation

- `app/Infrastructure/Jobs/Catalog/SyncShippingOffersFiltersJob.php` — job template (closest match)
- `app/Application/Catalog/UseCases/SyncShippingOffersFiltersUseCase.php` — UseCase template
- `app/Infrastructure/Catalog/Repositories/ShippingOffersFilterQueryRepository.php` — repository template
- `app/Domain/Catalog/Product/Enums/ShippingOffersFilterValue.php` — enum template
- `database/migrations/2026_04_11_200000_create_catalog_products_with_changed_offers_filters_view.php` — merge-preserving view template (the structural template for slot 25)
- `database/migrations/2026_01_17_050000_create_shopwired_products_table.php:25,41` — `external_id`, nullable `stock`
- `database/migrations/2026_01_17_050001_create_shopwired_product_variations_table.php:31,48` — `product_external_id`, not-null `stock`
- `tests/Unit/Application/Catalog/UseCases/SyncShippingOffersFiltersUseCaseTest.php` — test template
- `tests/Integration/Catalog/ShippingOffersFilterGroupGuardTest.php` — guard test template
- `app/Providers/Schedule/InventoryScheduleServiceProvider.php:43` — `everyTenMinutes()` + `withoutOverlapping(10)` reference
- `app/Providers/CatalogServiceProvider.php` — repository binding site
- `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php` — enum registration site

---

## Verification

1. **Migrate**: `php artisan migrate` — confirms view compiles.
2. **Spot-check the view** against a seeded local DB:
   ```sql
   SELECT * FROM catalog.products_with_changed_shipping_options_filters LIMIT 5;
   ```
   Rows = products whose slot 25 disagrees with their current stock state.
3. **Null-guard sanity**: verify a parent with `stock IS NULL` + one variation with `stock = 3` produces `desired_filter_values = ["Next Day Delivery Available"]` (regression guard for the UNKNOWN-boolean pitfall).
4. **Guard test — MUST FAIL when row absent, then pass after seeding** (critical meta-verification):

   Context: the existing guard tests (`OffersFilterGroupGuardTest`, `ShippingOffersFilterGroupGuardTest`) are structurally fail-closed — `assertNotNull($row, …)` throws and halts before property access. But they read from `DB::connection('pgsql')`, which is the **shared local Postgres** — any filter_group row inserted by an earlier dev session (manual SQL, one-off filter-group sync run, etc.) persists across test runs. On a dev machine where the row was ever seeded, the test will pass even if the sync pipeline that's supposed to seed it is broken or not wired up. That's exactly what the user suspected — the silent pass is real, but it's *state leakage in the shared DB*, not a broken assertion.

   - **Step 4a — Prove fail-closed**: Explicitly delete any stale row first, then run the test:
     ```
     php artisan db:execute "DELETE FROM shopwired.filter_groups WHERE external_id = 11412"
     make test -- --filter=ShippingOptionsFilterGroupGuardTest
     ```
     Expect failure with the `assertNotNull` message. If it passes, the delete hit the wrong connection — investigate before moving on.
   - **Step 4b — Seed via the real mechanism**: Trigger the existing ShopWired filter-group sync (whichever job/command repopulates `shopwired.filter_groups` from the ShopWired API). Verify the `external_id = 11412` row lands with the expected `option_no = 25`. Do **not** hand-insert — that would recreate the same state-leakage illusion.
   - **Step 4c — Confirm green**: Rerun the guard test, expect pass.
   - **Step 4d — Audit siblings** (scope note): Repeat steps 4a–4c for `OffersFilterGroupGuardTest`, `ShippingOffersFilterGroupGuardTest`, and `VatReliefFilterGroupGuardTest` to confirm each can genuinely round-trip. Any guard that can't reach green via the filter-group sync — only via hand-insert — indicates a broken upstream seed pipeline; flag and open a follow-up issue rather than silently hand-seeding.
5. **Unit test**:
   ```
   make test -- --filter=SyncShippingOptionsFiltersUseCaseTest
   ```
6. **Smoke test locally** via tinker (never prod):
   ```
   php artisan tinker --execute="\App\Infrastructure\Jobs\Catalog\SyncShippingOptionsFiltersJob::dispatch();"
   ```
   Tail `storage/logs/laravel.log` for `SyncShippingOptionsFilters: starting` → `dispatched N updates` and confirm `UpdateProductFilterJob` instances enqueue on `bulk`.
7. **Full check**: stop hook runs `make fix` + `make lint` + `make test`. No new PHPStan complexity baseline entries.

---

## Open Questions / Known Concerns

1. **Guard-test assertion shape** — RESOLVED. The existing guards are fail-closed. User's "silent pass" observation traces to **state leakage** in the shared local pgsql connection (row lingers from earlier dev sessions). Verification step 4 now handles this explicitly via `DELETE` → test → reseed → test.
2. **Filter-group seeding — confirmed blocker** — `shopwired.filter_groups` does not yet contain the `external_id = 11412 / option_no 25` row. Blocker for:
   - Guard test (step 4c).
   - `UpdateProductFilterJob` dispatches — they look up filter_groups by `option_no` to hit the ShopWired API, so without the row every dispatched job will fail its lookup.
   Resolution: trigger the existing ShopWired filter-group sync and verify the row lands. Do **not** enable the schedule entry in `CatalogScheduleServiceProvider` until the seed is in place. Do **not** hand-insert (reintroduces the state-leakage problem verification step 4d is trying to surface).
3. **Naming collision** — `ShippingOffers` (WIP, 11411, free_delivery custom field) vs `ShippingOptions` (this plan, 11412, stock). Very easy to mix up in review. Add a cross-reference in both class docblocks (`@see ShippingOffersFilterValue`) and call out the distinction in the PR description.
4. **Source-of-truth comment** — `shopwired.products.stock` and `product_variations.stock` are locally mirrored copies of Linnworks stock (populated by `SyncFullStockToShopwiredJob` and `SyncDeltaStockToShopwiredJob`). The user asked us to read from the ShopWired tables, not Linnworks — we comply at the query level, but the migration's SQL comment should record that the ultimate origin is Linnworks so a future reader chasing stale data knows where to look upstream.
5. **Schedule timing race with stock sync** — RESOLVED. Using `->cron('5-59/10 * * * *')` to offset ticks 5 min after `SyncFullStockToShopwiredJob`. Documented inline in the schedule registration so the reason the shape differs from siblings is obvious.
