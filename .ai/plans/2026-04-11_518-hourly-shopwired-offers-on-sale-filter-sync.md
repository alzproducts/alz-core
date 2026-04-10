# Plan — Hourly ShopWired "Offers / On sale" filter sync

## Context

Mirror of issue #516 (VAT-relief filter sync) for a different filter. The ShopWired **Offers** filter (external_id `10073`, optionNo `14`) has an **"On Sale"** option that drifts from the underlying product pricing state. An hourly job will:

1. Identify products where a sale is active on EITHER the main product OR any variant, using the canonical rule from `Product::isSaleActive()`:
   `sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price`
2. Compare that against the current `filters->'14'` JSONB on each product.
3. Dispatch per-product filter updates to realign the storefront.

The VAT-relief sync (issue #516) already shipped the shared plumbing (`ShopwiredFilterValueInterface`, `ProductFilterChangeDTO`, `CatalogSyncDispatcherInterface::dispatchFilterUpdate`, `UpdateProductFilterJob`), so this issue adds only per-filter files plus one new enum case.

**One critical deviation from the VAT-relief pattern** is that `filters->'14'` is a multi-value slot (shared with other Offers options like "Free Delivery", "New in", etc.). Because `UpdateProductFilterJob` replaces the entire optionNo slot with the array we send, the view must compute a **merge-preserving desired array** — not just `['On Sale']` / `[]` the way VAT relief does. See "SQL view" below.

---

## Confirmed inputs (from user / exploration)

- **Filter external_id**: `10073`
- **Filter optionNo**: `14`
- **Sale-active rule** (single source of truth — `app/Domain/Catalog/Product/ValueObjects/Product.php:235`):
  `sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price`
- **Variant-level inheritance**: `shopwired.product_variations.price = NULL` means "inherit parent price"; `0.00` means explicit removal. The variant check must `COALESCE(v.price, p.price)` when comparing against `v.sale_price`.
- **No date windows**: `shopwired.products` has no `sale_starts_at` / `sale_ends_at`. `product_sale_settings` is job metadata, NOT used for on-sale determination. Pricing alone decides.
- **Parent table**: `shopwired.products` (`external_id` = ShopWired product id, `price` NOT NULL decimal, `sale_price` nullable decimal).
- **Variants table**: `shopwired.product_variations` joined on `product_external_id = p.external_id`.

## Confirmed during Phase 1

- **Multi-value slot**: Confirmed by user. `filters->'14'` can hold siblings (e.g. "Free Delivery"), so the view must be merge-preserving.
- **Canonical casing: `"On Sale"` (title case)**. User decision after live query surfaced pre-existing drift: `filters->'14'` contained both `"On Sale"` (title case, 17 rows) and lowercase-s `"On sale"` (3 rows). The enum backing value, SQL view literal, and guard test all use title-case `"On Sale"`. First sync run will incidentally clean up the 3 lowercase-s rows as a data-hygiene side-effect — flag this in the PR notes so reviewers aren't surprised by those 3 extra writes.

---

## Files — create / modify (mirrors #516)

### New per-filter files
| VAT-relief file (reference) | Offers twin |
|---|---|
| `app/Infrastructure/Jobs/Catalog/SyncVatReliefFiltersJob.php` | `SyncOffersFiltersJob.php` |
| `app/Application/Catalog/UseCases/SyncVatReliefFiltersUseCase.php` | `SyncOffersFiltersUseCase.php` |
| `app/Application/Contracts/Catalog/VatReliefFilterQueryRepositoryInterface.php` | `OffersFilterQueryRepositoryInterface.php` |
| `app/Infrastructure/Catalog/Repositories/VatReliefFilterQueryRepository.php` | `OffersFilterQueryRepository.php` |
| `database/migrations/2026_04_11_100000_create_catalog_products_with_changed_vat_relief_filters_view.php` | `{ts}_create_catalog_products_with_changed_offers_filters_view.php` |
| `tests/Integration/Catalog/VatReliefFilterGroupGuardTest.php` | `OffersFilterGroupGuardTest.php` |
| `tests/Unit/Application/Catalog/UseCases/SyncVatReliefFiltersUseCaseTest.php` | `SyncOffersFiltersUseCaseTest.php` |
| `app/Domain/Catalog/Product/Enums/VatReliefFilterValue.php` | `OffersFilterValue.php` |

### Modified
- `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php` — add `case Offers = 14;` and update class docblock to list all three guard tests.
- `app/Providers/CatalogServiceProvider.php` — bind `OffersFilterQueryRepositoryInterface` → `OffersFilterQueryRepository` and add to `provides()`.
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — add `registerOffersFilterSchedule()`; update class docblock.

### NOT modified (shared infra is already filter-agnostic after #516)
`ShopwiredFilterValueInterface`, `ProductFilterChangeDTO`, `CatalogSyncDispatcherInterface`, `QueuedCatalogSyncDispatcher`, `UpdateProductFilterJob`, `DTO unit test`.

---

## SQL view — `catalog.products_with_changed_offers_filters`

Multi-value slot, merge-preserving (user-confirmed). The view preserves any sibling values in `filters->'14'` and only toggles the `"On Sale"` entry based on the canonical `isSaleActive` rule.

```sql
CREATE OR REPLACE VIEW catalog.products_with_changed_offers_filters AS
WITH product_sale_state AS (
    SELECT
        p.external_id AS product_id,
        p.filters,
        (
            (p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price)
            OR EXISTS (
                SELECT 1
                FROM shopwired.product_variations v
                WHERE v.product_external_id = p.external_id
                  AND v.sale_price IS NOT NULL
                  AND v.sale_price > 0
                  AND v.sale_price < COALESCE(v.price, p.price)
            )
        ) AS is_on_sale
    FROM shopwired.products p
),
desired AS (
    SELECT
        pss.product_id,
        pss.filters,
        pss.is_on_sale,
        -- Start from current entries in filters->'14' excluding ANY casing of "on sale"
        -- (preserves siblings like "Free Delivery", normalises legacy casings like "On sale"),
        -- then append the canonical "On Sale" if the product should be flagged.
        COALESCE(
            (
                SELECT jsonb_agg(value ORDER BY value)
                FROM jsonb_array_elements_text(COALESCE(pss.filters->'14', '[]'::jsonb)) AS value
                WHERE LOWER(value) <> 'on sale'
            ),
            '[]'::jsonb
        )
        || CASE WHEN pss.is_on_sale THEN '["On Sale"]'::jsonb ELSE '[]'::jsonb END
        AS desired_filter_values_json
    FROM product_sale_state pss
)
SELECT
    product_id,
    -- Cast jsonb back to text[] for the repository / enum parser
    ARRAY(SELECT jsonb_array_elements_text(desired_filter_values_json)) AS desired_filter_values
FROM desired
-- 14 = FilterGroupOptionNo::Offers (keep in sync with FilterGroupOptionNo.php)
WHERE (
    SELECT jsonb_agg(value ORDER BY value)
    FROM jsonb_array_elements_text(COALESCE(filters->'14', '[]'::jsonb)) AS value
) IS DISTINCT FROM (
    SELECT jsonb_agg(value ORDER BY value)
    FROM jsonb_array_elements_text(desired_filter_values_json) AS value
);
```

Notes:
- Both sides of the `IS DISTINCT FROM` are sorted via `jsonb_agg(... ORDER BY value)` so diff is order-insensitive. (A product whose filters-14 is `["Free Delivery","On Sale"]` and whose desired is `["On Sale","Free Delivery"]` must not produce a spurious drift row.)
- No `is_active` filter — matches VAT-relief precedent; ShopWired still owns inactive products, and inactive products can be drift sources for when they're re-activated. Flag to user if they want active-only filtering.
- Returns `text[]` (not `jsonb`) for `desired_filter_values` to keep the repository parser (`OffersFilterValue::fromPostgresArray()`) identical in shape to the VAT-relief path. If the user prefers a `jsonb` return we'd change the parser too.

---

## `OffersFilterValue` enum

Clone of `VatReliefFilterValue`, single case `case OnSale = 'On Sale';` (pending casing confirmation). Implements `ShopwiredFilterValueInterface`. Same `fromString` / `fromPostgresArray` helpers. No unit tests (matches VAT-relief and rating precedent).

## `OffersFilterQueryRepository`

Identical shape to `VatReliefFilterQueryRepository`. Selects from `catalog.products_with_changed_offers_filters`, constructs `ProductFilterChangeDTO` via `new ProductFilterChangeDTO(IntId::from($row->product_id), FilterGroupOptionNo::Offers->value, OffersFilterValue::fromPostgresArray($row->desired_filter_values))`. Declares `@throws InvalidEnumValueException`.

## `SyncOffersFiltersUseCase`

Clone of `SyncVatReliefFiltersUseCase`. Only deltas:
- Depends on `OffersFilterQueryRepositoryInterface`
- Log prefix: `SyncOffersFilters:`
- Repository method: `getProductsWithChangedOffersFilters()`
- Dispatcher method remains `dispatchFilterUpdate` (shared).

## `SyncOffersFiltersJob`

Clone of `SyncVatReliefFiltersJob`. Only delta: `uniqueId(): 'sync-offers-filters'` and UseCase type. All queue/retry/timeout/backoff/middleware values byte-identical.

## `SyncOffersFiltersUseCaseTest`

Clone of `SyncVatReliefFiltersUseCaseTest`. Same four scenarios (empty, dispatches, null-for-removal, mixed). Substitute `OffersFilterValue::OnSale` for `VatReliefFilterValue::Yes`, optionNo `14`.

## `OffersFilterGroupGuardTest`

Clone of `VatReliefFilterGroupGuardTest`. Asserts `external_id = 10073` AND `option_no = 14`. **Do not assert title** — admin-editable. If a sanity title check is desired, use `stripos($row->title, 'offer') !== false`.

## `FilterGroupOptionNo` enum

Add `case Offers = 14;` in declared numeric order (after `VatRelief = 2`, before `CustomerRating = 15`). Update the class docblock to reference `OffersFilterGroupGuardTest`.

## `CatalogScheduleServiceProvider`

Add `registerOffersFilterSchedule()`. Same cadence: `->hourly()->timezone('Europe/London')->onOneServer()->withoutOverlapping(30)`. Schedule name: `sync-offers-filters`. Update class docblock.

## `CatalogServiceProvider`

```php
$this->app->scoped(
    OffersFilterQueryRepositoryInterface::class,
    OffersFilterQueryRepository::class,
);
```
Add interface to `provides()`.

---

## Critical pitfalls

- **Multi-value slot clobber** (the whole reason for the merge-preserving view). Naive `['On Sale']` / `[]` writes would destroy coexisting Offers filter values. Mitigation: view computes the full desired array, as described above.
- **Stale read race on sibling values**: view reads `shopwired.products.filters` from our DB, but if an admin edits Offers filters in ShopWired between view-query and API-PUT, we could revert their change to a sibling value. Same race as #516. Accepted — next hourly run corrects. Flag to user if tighter semantics are required (per-product Cache lock in `UpdateProductFilterJob`).
- **Variant price inheritance**: `COALESCE(v.price, p.price)` is mandatory in the variant EXISTS check. Variants with `price = NULL` and only `sale_price` set must compare sale against the parent price.
- **Variant price `0.00`**: per the migration comment, `0.00` means "temporarily removed from sale". The `sale_price > 0` clause correctly excludes this.
- **Canonical casing is `"On Sale"`** (title case). Live DB currently has 3 rows using lowercase-s `"On sale"`; the first sync run will normalise them. Keep the enum backing value, view literal, and any test fixtures exactly as `"On Sale"` — a typo here would silently create a second Offers tickbox on the storefront.
- **Order sensitivity in diff**: `jsonb_agg(... ORDER BY value)` on both sides of `IS DISTINCT FROM` avoids false drift for products whose array order has simply shifted.
- **PHPStan `@throws`**: repository/UseCase must declare `InvalidEnumValueException` (thrown by `OffersFilterValue::fromString`) — follow the VAT-relief declarations verbatim.

## Edge cases

- **E1 — Product with no variants, no sale** → EXISTS returns false, parent sale check false → `is_on_sale = false`. If `filters->'14'` currently has no `"On Sale"`, no drift row.
- **E2 — Product with only variant-level sale** → parent check false, EXISTS true → flagged on-sale.
- **E3 — Product with parent sale but variants with `sale_price = 0.00`** → parent check true → flagged on-sale regardless of variants. Correct.
- **E4 — Product with `filters->'14'` absent entirely** → `COALESCE(filters->'14', '[]'::jsonb)` handles the null. Diff behaves as if slot were empty.
- **E5 — Product with `filters->'14' = ["Free Delivery"]` and is on sale** → desired = `["Free Delivery","On Sale"]`. Sibling preserved.
- **E6 — Product with `filters->'14' = ["On Sale","Free Delivery"]` and no longer on sale** → desired = `["Free Delivery"]`. On-sale removed, sibling preserved.

---

## Verification

1. **Guard test in isolation first**: `make test tests/Integration/Catalog/OffersFilterGroupGuardTest.php` — confirms filter 10073 / optionNo 14 exist in local DB.
2. **Lint/typecheck/test**: `make lint` then `make test`.
3. **Drift count sanity check**: `php artisan tinker --execute="echo DB::selectOne('SELECT COUNT(*) AS c FROM catalog.products_with_changed_offers_filters')->c;"` — expect non-trivial count pre-sync, zero post-sync.
4. **Pick a known-on-sale product** (one with `sale_price < price`) and confirm it appears in the view when `filters->'14'` doesn't include "On Sale".
5. **Pick a product with a variant-only sale** and confirm same.
6. **Pick a product with siblings in `filters->'14'`** (e.g. "Free Delivery") and confirm view returns desired array preserving the sibling.
7. **Smoke dispatch locally**: `php artisan tinker --execute="App\Infrastructure\Jobs\Catalog\SyncOffersFiltersJob::dispatch();"`. Watch `storage/logs/laravel.log` for `SyncOffersFilters: dispatched …`.
8. **Queue drains, then re-run**: expect `SyncOffersFilters: no products …` — a non-zero second run means the view diff and the API write aren't round-tripping (likely casing mismatch or order-insensitive diff bug).
9. **Manual admin eyeball**: open one previously-drifted product in ShopWired admin and confirm the Offers → "On Sale" checkbox matches expectation.
10. **Regression guard on #516**: re-dispatch `SyncVatReliefFiltersJob` after Offers work lands to confirm nothing in the shared refactor regressed.

---

## Implementation order (commit per step)

1. `FilterGroupOptionNo::Offers = 14` + `OffersFilterValue` enum (standalone, no wiring).
2. SQL view migration + `php artisan migrate` + `make lint`/`make test`.
3. `OffersFilterGroupGuardTest` (run in isolation).
4. Repository interface + impl + service-provider binding.
5. UseCase + UseCase test.
6. Orchestrator job.
7. Schedule registration.
8. Full `make lint` + `make test`, smoke dispatch, drift-count verify, manual eyeball.

---

## Critical files to read during execution

- `app/Domain/Catalog/Product/Contracts/ShopwiredFilterValueInterface.php`
- `app/Domain/Catalog/Product/Enums/VatReliefFilterValue.php` (clone target)
- `app/Application/Catalog/DTOs/ProductFilterChangeDTO.php`
- `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php`
- `app/Infrastructure/Catalog/Repositories/VatReliefFilterQueryRepository.php` (clone target)
- `app/Application/Catalog/UseCases/SyncVatReliefFiltersUseCase.php` (clone target)
- `app/Infrastructure/Jobs/Catalog/SyncVatReliefFiltersJob.php` (clone target)
- `app/Infrastructure/Jobs/Catalog/UpdateProductFilterJob.php` (shared, do NOT modify)
- `database/migrations/2026_04_11_100000_create_catalog_products_with_changed_vat_relief_filters_view.php`
- `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php`
- `app/Providers/CatalogServiceProvider.php`
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php`
- `tests/Integration/Catalog/VatReliefFilterGroupGuardTest.php`
- `tests/Unit/Application/Catalog/UseCases/SyncVatReliefFiltersUseCaseTest.php`
- `app/Domain/Catalog/Product/ValueObjects/Product.php` (L235 — canonical `isSaleActive()` rule)
