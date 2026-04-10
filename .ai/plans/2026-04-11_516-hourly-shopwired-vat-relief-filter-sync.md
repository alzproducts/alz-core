# Plan — Hourly ShopWired "Eligible for VAT Relief" filter sync

## Context

`shopwired.products.vat_relief` is a (nullable) boolean we own in the database, but the **customer-facing** VAT-relief status on the storefront is driven by a ShopWired product filter:

- **Filter external_id**: `240`
- **Filter optionNo**: `2`
- **Filter title** (live): `"Eligible for VAT Relief?"` (admin-editable — identification must use IDs, not this string)
- **Stored form** (in `shopwired.products.filters` JSONB, confirmed by DB sample):
  - `vat_relief = TRUE` → `filters->'2' = ["Yes"]`
  - `vat_relief = FALSE` → `filters->'2'` absent (key not set)
  - `vat_relief = NULL` → **unknown / not yet synced from product embed** — MUST be skipped

A snapshot today shows **57 products currently drift**:
- 9 rows with `vat_relief = false` but `filters->'2' = ["Yes"]` (filter over-applied)
- 48 rows with `vat_relief = true` but `filters->'2'` missing (storefront not showing VAT relief eligibility)

Drift in this filter has real customer/legal impact (VAT-relief eligibility is a statutory discount for disabled customers), so it must be kept accurate.

The existing `SyncRatingFiltersJob` hourly sync already solves the identical general problem — "boolean/derived state in DB needs to match a ShopWired product filter" — for customer ratings. This plan clones that feature for VAT relief and, while doing so, pushes reuse one level deeper than the rating feature did: strip per-enum leaks out of the shared DTO/dispatcher/worker so the pattern becomes genuinely filter-agnostic below the UseCase layer.

---

## Core directive

**Clone the existing rating-filter sync for VAT relief, but promote three things to shared infrastructure during the refactor: the DTO, the dispatcher method, and the per-product worker job.** The only per-filter files should be: the enum of filter values, the SQL view, the repository, the orchestrator job, the UseCase, the schedule registration, and the guard test.

The rating feature (plan: `.ai/plans/2026-04-04_476-hourly-customer-rating-filter-sync.md`) already defines: job shape, queue assignments, retry policy, timeouts, middleware, logging, SQL-view diff strategy, schedule cadence, guard test, service binding, unit test structure. Keep all of that identical — only the deltas below change per-filter.

---

## Files — create, clone, modify, rename

### New per-filter files (VAT-relief twins of rating equivalents)
| Rating file | VAT-relief twin |
|---|---|
| `app/Infrastructure/Jobs/Catalog/SyncRatingFiltersJob.php` | `SyncVatReliefFiltersJob.php` (orchestrator — stays per-filter because of `ShouldBeUnique` id) |
| `app/Application/Catalog/UseCases/SyncRatingFiltersUseCase.php` | `SyncVatReliefFiltersUseCase.php` |
| `app/Application/Contracts/Catalog/RatingFilterQueryRepositoryInterface.php` | `VatReliefFilterQueryRepositoryInterface.php` |
| `app/Infrastructure/Catalog/Repositories/RatingFilterQueryRepository.php` | `VatReliefFilterQueryRepository.php` |
| `database/migrations/2026_04_04_013553_create_catalog_products_with_changed_rating_filters_view.php` | `create_catalog_products_with_changed_vat_relief_filters_view.php` |
| `tests/Integration/Catalog/CustomerRatingFilterGroupGuardTest.php` | `VatReliefFilterGroupGuardTest.php` |
| `tests/Unit/Application/Catalog/UseCases/SyncRatingFiltersUseCaseTest.php` | `SyncVatReliefFiltersUseCaseTest.php` |

### New shared files
- `app/Domain/Catalog/Product/Enums/ShopwiredFilterValue.php` — empty marker interface. Existing `RatingFilterValue` and new `VatReliefFilterValue` both `implements ShopwiredFilterValue`. The shared DTO/dispatcher/worker all type against `ShopwiredFilterValue&\BackedEnum`.
- `app/Domain/Catalog/Product/Enums/VatReliefFilterValue.php` — new backed enum, single case `Yes = 'Yes'`, same helpers as `RatingFilterValue`, implements `ShopwiredFilterValue`.

### Renamed (shared worker promotion)
- `app/Infrastructure/Jobs/Catalog/UpdateProductRatingFilterJob.php` → **`UpdateProductFilterJob.php`**. Use `git mv` to preserve history. Verified filter-agnostic: `handle()` is one line — `$updateClient->updateFilters($this->productId->value, [$this->optionNo => $this->filterValues])`. Only `ShouldQueue`, not `ShouldBeUnique` — no `uniqueId` to scope. Both orchestrators dispatch to this one class.

### Modified (shared refactor + integration points)
- `app/Application/Catalog/DTOs/ProductFilterChangeDTO.php` — generalised to `list<ShopwiredFilterValue&\BackedEnum>`; `fromViewRow` removed (repositories construct directly).
- `tests/Unit/Application/Catalog/DTOs/ProductFilterChangeDTOTest.php` — switched from raw strings to enum cases (incidentally fixes a pre-existing type lie at line 25).
- `app/Domain/Catalog/Product/Enums/RatingFilterValue.php` — `implements ShopwiredFilterValue`; `toStringArray()` deleted (dead after dispatcher refactor).
- `app/Infrastructure/Catalog/Dispatchers/QueuedCatalogSyncDispatcher.php` — collapse to one method `dispatchFilterUpdate()`, generic `array_map(fn(\BackedEnum $v) => $v->value, $values)` for enum → string conversion.
- `app/Application/Contracts/Catalog/CatalogSyncDispatcherInterface.php` — rename `dispatchRatingFilterUpdate` → `dispatchFilterUpdate`, type `list<ShopwiredFilterValue&\BackedEnum>|null`.
- `app/Infrastructure/Catalog/Repositories/RatingFilterQueryRepository.php` — construct DTO via `new` (not `fromViewRow`); inline `RatingFilterValue::fromPostgresArray()` call.
- `app/Infrastructure/Shopwired/Enums/FilterGroupOptionNo.php` — add `VatRelief = 2`; update class docblock to reference both guard tests (L2).
- `app/Application/Catalog/UseCases/SyncRatingFiltersUseCase.php` — update dispatcher method call to `dispatchFilterUpdate`.
- `tests/Unit/Application/Catalog/UseCases/SyncRatingFiltersUseCaseTest.php` — update `shouldReceive('dispatchRatingFilterUpdate')` → `dispatchFilterUpdate`.
- `app/Providers/CatalogServiceProvider.php` — add `VatReliefFilterQueryRepositoryInterface` binding + `provides()` entry.
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — add `registerVatReliefFilterSchedule()`; update class docblock to cover both syncs (L1).

---

## Shared refactor (do this first, fully, before any VAT code)

Goal: make the DTO, dispatcher, and worker job filter-agnostic without changing rating-sync behaviour.

1. Create `ShopwiredFilterValue` marker interface in `app/Domain/Catalog/Product/Enums/`. Empty body. Purpose: type-safety seam. PHPStan will accept `ShopwiredFilterValue&\BackedEnum` in docblocks for `->value` access.
2. `RatingFilterValue` now `implements ShopwiredFilterValue`. Delete `toStringArray()` (only caller was the dispatcher).
3. `ProductFilterChangeDTO`:
   - `desiredFilterValues` docblocked as `list<ShopwiredFilterValue&\BackedEnum>`.
   - Remove `fromViewRow()` entirely.
   - Constructor remains the public entry point. `filterValuesForDispatch()` logic is unchanged, but its return docblock must be updated from `list<RatingFilterValue>|null` to `list<ShopwiredFilterValue&\BackedEnum>|null`.
   - Delete the `use App\Domain\Catalog\Product\Enums\RatingFilterValue;` import (no longer referenced).
4. `RatingFilterQueryRepository` — replace `ProductFilterChangeDTO::fromViewRow($row->product_id, $row->desired_filter_values, $optionNo)` with `new ProductFilterChangeDTO(IntId::from($row->product_id), $optionNo, RatingFilterValue::fromPostgresArray($row->desired_filter_values))`. Repository `@throws InvalidEnumValueException` (already declared).
5. `CatalogSyncDispatcherInterface`:
   - Rename `dispatchRatingFilterUpdate` → `dispatchFilterUpdate`.
   - Typed `list<ShopwiredFilterValue&\BackedEnum>|null $values`.
   - (Follow-up, not blocking: add `@throws` declarations — pre-existing gap per CLAUDE.md. Mention in PR notes but don't scope-creep.)
6. `QueuedCatalogSyncDispatcher::dispatchFilterUpdate` — dispatches `UpdateProductFilterJob` (the renamed worker), enum-to-string conversion via `array_map(static fn(\BackedEnum $v): string => (string) $v->value, $values)` (inline — no helper).
7. `git mv UpdateProductRatingFilterJob.php UpdateProductFilterJob.php`, rename class, update namespace references. Verify with Grep — only the dispatcher references it.
8. Update `SyncRatingFiltersUseCase` to call `dispatchFilterUpdate` (method rename).
9. Update `SyncRatingFiltersUseCaseTest` — swap `shouldReceive('dispatchRatingFilterUpdate')` for `shouldReceive('dispatchFilterUpdate')`.
10. Update `ProductFilterChangeDTOTest` — replace the three tests' `['4', '4.5']`/`['4']`/`[]` string arrays with enum cases: `[RatingFilterValue::FourStars, RatingFilterValue::FourAndHalfStars]`, etc. Incidentally corrects a pre-existing type lie where the test passed strings despite the docblock declaring enum cases.

**After step 10, the rating sync is behaviour-identical end-to-end** (same view, same payload, same API call). Verify by running `SyncRatingFiltersJob::dispatch()` locally and confirming `storage/logs/laravel.log` shows either "no products…" or the same dispatch count as pre-refactor. Commit this as a standalone behaviour-preserving refactor before any VAT code.

---

## VAT-relief additions (after the shared refactor is green)

1. **`FilterGroupOptionNo` enum** — add `case VatRelief = 2;`. Update the class docblock to reference both `CustomerRatingFilterGroupGuardTest` and `VatReliefFilterGroupGuardTest`.

2. **`VatReliefFilterValue` enum** — `app/Domain/Catalog/Product/Enums/`. Single backed case `Yes = 'Yes'` (title-case — DB sample confirms this exact casing). `implements ShopwiredFilterValue`. Clone `RatingFilterValue`'s static helpers verbatim (`fromString` throwing `InvalidEnumValueException`, `fromPostgresArray`). A single-case enum looks odd but matches the pattern and is trivially extensible.

   **No enum unit tests** — `RatingFilterValue` has no tests either (verified: no `RatingFilterValueTest` file exists), so matching the pattern means none here. The enum is exercised indirectly via the sync smoke run.

3. **SQL view** — new migration creating `catalog.products_with_changed_vat_relief_filters`. Completely different body from the rating view (no reviews_io join, no weighted average, no CTE, no thresholds):
   - Source: `shopwired.products p` only.
   - **`WHERE p.vat_relief IS NOT NULL`** — null means "unknown/not yet synced from embed"; clearing the filter on unknown rows would be a regression.
   - `desired_filter_values` = `CASE WHEN p.vat_relief THEN ARRAY['Yes'] ELSE ARRAY[]::text[] END`.
   - Diff: `COALESCE(p.filters->'2', '[]'::jsonb) IS DISTINCT FROM to_jsonb(desired_filter_values)`.
   - Include a comment on the hardcoded `'2'` key matching `FilterGroupOptionNo::VatRelief`, mirroring the rating view's comment on `'15'`.
   - Returns `product_id` (= `p.external_id`) and `desired_filter_values`.

4. **`VatReliefFilterQueryRepository`** — same shape as `RatingFilterQueryRepository`:
   - `SELECT … FROM catalog.products_with_changed_vat_relief_filters`
   - `$optionNo = FilterGroupOptionNo::VatRelief->value`
   - Constructs DTO via `new ProductFilterChangeDTO(IntId::from($row->product_id), $optionNo, VatReliefFilterValue::fromPostgresArray($row->desired_filter_values))`.
   - `@throws InvalidEnumValueException` propagated to interface.

5. **`SyncVatReliefFiltersJob` orchestrator** — clone `SyncRatingFiltersJob` exactly. Only deltas:
   - `uniqueId(): 'sync-vat-relief-filters'`
   - Dependency type: `SyncVatReliefFiltersUseCase`
   - Everything else (queue, `tries`, `maxExceptions`, `timeout`, `failOnTimeout`, `uniqueFor`, `backoff`, `middleware`, `retryUntil`) is byte-identical.

6. **`SyncVatReliefFiltersUseCase`** — clone `SyncRatingFiltersUseCase` exactly. Only deltas:
   - Dependency type: `VatReliefFilterQueryRepositoryInterface`
   - Log prefix: `SyncVatReliefFilters:`
   - Repository method called: `getProductsWithChangedVatReliefFilters()`
   - Dispatcher method called: `dispatchFilterUpdate` (same method used by the rating sync post-refactor).

7. **`SyncVatReliefFiltersUseCaseTest`** — clone `SyncRatingFiltersUseCaseTest`. Swap `RatingFilterValue::FourStars`/`FourAndHalfStars` → `VatReliefFilterValue::Yes`. OptionNo `2` throughout. Four scenarios: empty, dispatches, null-for-removal, mixed.

8. **Schedule** — add `registerVatReliefFilterSchedule()` private method in `CatalogScheduleServiceProvider::boot()`. Same cadence: `->hourly()->timezone('Europe/London')->onOneServer()->withoutOverlapping(30)`. Schedule name: `sync-vat-relief-filters`. Update the class docblock to describe both syncs (L1).

9. **Service provider** — `CatalogServiceProvider`:
   - Add `$this->app->scoped(VatReliefFilterQueryRepositoryInterface::class, VatReliefFilterQueryRepository::class);`
   - Add `VatReliefFilterQueryRepositoryInterface::class` to `provides()`.
   - Dispatcher binding unchanged (single `CatalogSyncDispatcherInterface` → `QueuedCatalogSyncDispatcher`, which now serves both syncs via one method).

10. **Guard test** — `VatReliefFilterGroupGuardTest`. Look up by **`external_id = 240`** (the stable ID the user called out) and assert `option_no = 2`. **Do NOT assert the title equals any exact string** — titles are admin-editable in ShopWired and the live title has a trailing `?` that could be edited away. If any title check is wanted, use `stripos($row->title, 'vat relief') !== false` (case-insensitive) so it survives minor edits. (`str_contains()` is case-sensitive in PHP — don't use it here.)

---

## Things NOT to copy from the rating sync

- The `reviews_io.product_ratings` join, `product_averages` CTE, weighted-average arithmetic — VAT relief is a direct boolean, no aggregation.
- The `4.0` / `4.5` threshold `CASE`.
- Multi-value array handling — VAT relief has at most one value (`['Yes']` or `[]`).

---

## Critical pitfalls

- **Nullable `vat_relief`**: the view MUST filter `WHERE vat_relief IS NOT NULL`. `null` means "unknown/not yet synced from embed" (introduced by migration `2026_03_13_104001_make_shopwired_products_embed_columns_nullable.php`). Clearing the filter on unknown rows would be a regression.
- **Case-sensitive value**: ShopWired stores the value as exactly `"Yes"` (title-case). The enum backing value must match — do not lowercase.
- **Filter identification must use IDs, not title**: live title has a trailing `?` and is admin-editable. Use `external_id=240` + `option_no=2`.
- **Shared refactor must be behaviour-preserving**: if anything about the rating path feels like it's changing semantically, stop and flag.
- **Same-product fetch-merge-PUT race (H1)**: `ProductUpdateClient::updateFilters` fetches current product state from the live ShopWired API, merges the target optionNo, and PUTs the whole `filters` object back. Two different syncs updating the same product within the same rate-limit window can both fetch the same stale snapshot, compute divergent merges, and the later writer wins — silently reverting the earlier change. Per-optionNo merges (`mergeFilters` in `ProductUpdateClient.php:108-121`) mean this only bites when two syncs target the same product, which is rare. **Accepted mitigation**: eventual consistency — next hourly run catches up. If we ever see repeated drift on the same product, introduce a `Cache::lock("product-filter-{productId}")` around `updateFilters` in `UpdateProductFilterJob::handle()`.
- **Guard test title is fragile**: do NOT assert exact title matches. Admin edits to casing/punctuation would break CI.

## Edge cases (all expected-safe, no action needed)

- **E1**: Products in Linnworks but not yet in `shopwired.products` — the view joins only on `shopwired.products`, so they're naturally excluded.
- **E2**: `vat_relief` transitioning NULL → TRUE/FALSE between runs — picked up on the next hourly run once the column is non-null.
- **E3**: Local `shopwired.products.filters` JSONB updated by webhook between view query and API PUT — the PUT reads fresh from the ShopWired API (`getProductById`), not from our JSONB, so local staleness doesn't corrupt the merge. The view diff might be wrong by one tick but next run corrects it.
- **E4**: Malformed legacy filter like `["Yes", "No"]` — DB sample shows no such rows today, but `IS DISTINCT FROM` correctly flags them and the sync would overwrite with the canonical `["Yes"]` or `[]`.

---

## Verification

1. **Rating regression check FIRST** (before any VAT code): after the shared refactor commits, run `make lint` then `make test`. Dispatch `SyncRatingFiltersJob::dispatch()` via tinker. Check `storage/logs/laravel.log` for either "no products…" or the expected dispatch count — must match pre-refactor behaviour for that moment.
2. **Lint/typecheck/tests** after VAT code: `make lint` then `make test` — should pass green.
3. **Guard test on its own**: filtered run to `VatReliefFilterGroupGuardTest` — confirms filter 240 exists and has optionNo 2.
4. **View dry-run before dispatching**: `php artisan tinker --execute="echo json_encode(DB::select('SELECT COUNT(*) FROM catalog.products_with_changed_vat_relief_filters'));"` — expect ~57 (matches today's drift snapshot).
5. **Smoke dispatch locally**: `php artisan tinker --execute="App\Infrastructure\Jobs\Catalog\SyncVatReliefFiltersJob::dispatch();"`. Queue listener picks it up; check logs for `SyncVatReliefFilters: dispatched …` with count ~57.
6. **Re-run after the bulk jobs drain**: expect `SyncVatReliefFilters: no products …`. Any non-zero second run means the view diff and the API write aren't round-tripping correctly.
7. **Manual eyeball**: pick one previously-drifted product (e.g. one from the `vat_relief=true, filters->'2' IS NULL` bucket) and confirm the filter flips on in ShopWired admin.

---

## Implementation order

Each numbered block should land as its own commit for easy review and rollback.

1. **Shared refactor** (single commit): marker interface, DTO generalisation, repository/dispatcher/use-case/test updates, `git mv` of the worker job. End with a local rating-sync dispatch to prove no behavioural drift. **Commit and stop here to verify green before proceeding.**
2. **Enum + optionNo case**: `FilterGroupOptionNo::VatRelief = 2`, new `VatReliefFilterValue` enum with `Yes` case.
3. **SQL view migration + `php artisan migrate`**, then `make lint` + `make test`.
4. **Guard test** — run in isolation before proceeding.
5. **Repository interface + implementation**.
6. **Orchestrator job + UseCase + UseCase test**.
7. **Service provider binding + schedule registration**.
8. **Full `make lint` + `make test`**, local smoke dispatch, manual product eyeball, re-run both syncs to verify clean state.
