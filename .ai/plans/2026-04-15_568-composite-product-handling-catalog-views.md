# Plan: Composite Product Handling in Catalog Views

## Context

Products made of composites (assembled from other items) don't carry their own cost prices. The frontend can't determine a default supplier for these products because composite variants are included in the `commonDefaultSupplier()` calculation, poisoning the result. We need to surface `isComposite` at the variation level, exclude composites from supplier calculations, and disable cost price editing for composites.

### Data Reliability — IsCompositeParent from Linnworks

Tested both endpoints for SKU `1005814` (known composite parent):

| Endpoint | Returns `IsCompositeParent`? | Used by |
|----------|----------------------------|---------|
| `GetStockItemsFullByIds` | **Yes** (`true`) | `SyncStockItemBatchUseCase` → `RefreshProductViewUseCase` (on-demand per-product refresh) |
| `GetStockItemsFull` | **No** (field absent) | Bulk paginated inventory sync (scheduled) |

The `StockItemFullResponse` DTO doesn't map the field — hardcodes `isComposite: false`. Since both endpoints use the same DTO, **the bulk sync overwrites correct values to `false`**.

**Fix (two parts):**
1. Stop the bulk sync clobbering: make `isComposite` nullable in the DTO/domain so bulk sync passes `null` → mapper skips writing the column
2. Backfill correct data: add a lightweight SQL Dashboards query that syncs `bContainsComposites` for all active stock items on a schedule

---

## Changes

### 0a. `StockItemFullResponse` — Stop clobbering isComposite
**File:** `app/Infrastructure/Linnworks/Responses/StockItemFullResponse.php`

- Add `public readonly ?bool $isCompositeParent = null` property to the DTO
- Update `toDomain()`: replace `isComposite: false` with `isComposite: $this->isCompositeParent`
- This makes `StockItemFull::$isComposite` nullable (`?bool`)

**File:** `app/Domain/Inventory/ValueObjects/StockItemFull.php`

- Change `public bool $isComposite` → `public ?bool $isComposite`
- `null` means "endpoint didn't tell us" (bulk sync), `true`/`false` means known

**File:** `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php`

- In `toModelAttributes()`: only include `is_composite` key when value is not null
  - `...($stockItem->isComposite !== null ? ['is_composite' => $stockItem->isComposite] : [])`
  - Bulk sync preserves existing DB value; ByIds/archived syncs write the correct value

**Files verified — no changes needed:**
- `ArchivedStockItemsFullQuery` — passes `bool` (assignable to `?bool`) ✅
- `StockItemModel::toProductInventory()` — reads DB column (always `bool`) ✅
- `StockItemResponse` (single item) — passes `$this->isCompositeParent ?? false` ✅

### 0b. Composite flags sync — Backfill via SQL Dashboards
**New file:** `app/Infrastructure/Linnworks/Queries/CompositeStockItemFlagsQuery.php`

Lightweight query following existing pattern (see `ArchivedStockItemsFullQuery`):
- SQL: `SELECT s.pkStockItemID, s.bContainsComposites FROM [StockItem] s WHERE s.IsArchived = 0 AND s.ItemNumber IS NOT NULL AND s.ItemNumber <> ''`
- Returns list of `{stockItemId, isComposite}` pairs — minimal data
- Maps `bContainsComposites` string ('True'/'False') → `bool`

**New file:** `app/Application/Linnworks/UseCases/SyncCompositeStockItemFlagsUseCase.php`

- Executes the query via `StockDashboardsClient`
- Bulk-updates `linnworks.stock_items.is_composite` for matching rows
- Follows existing pattern from `SyncArchivedStockItemFlagsUseCase`

**New file:** `app/Infrastructure/Jobs/Linnworks/SyncCompositeStockItemFlagsJob.php`

- Dispatches the use case
- Scheduled in `LinnworksScheduleServiceProvider`

**File:** `app/Application/Contracts/Linnworks/StockDashboardsClientInterface.php`

- Add method: `getCompositeStockItemFlags(): array` returning list of `{stockItemId, isComposite}` pairs
- Same `@throws` pattern as `getArchivedStockItemIds()`

**File:** `app/Infrastructure/Linnworks/Clients/StockDashboardsClient.php`

- Implement `getCompositeStockItemFlags()` — executes `CompositeStockItemFlagsQuery` via transport

**File:** `app/Providers/Schedule/LinnworksScheduleServiceProvider.php`

- Add schedule: hourly (or every 4 hours), same pattern as archived flags sync

**File:** `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php`

- Add method: `updateCompositeFlags(array $flags): void` (bulk update `is_composite` by `stock_item_id`)

**File:** `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php`

- Implement `updateCompositeFlags()` — simple bulk UPDATE via cases or chunked updates

### 1. `ProductVariationView` — Add isComposite, optional inventory, canEditCostPrice
**File:** `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php`

Two new fields with distinct purposes:
- Add `bool $isComposite = false` constructor param — always populated from stockItem, used for business logic + always serialized. Defaults to `false` when no stockItem exists.
- Add `?ProductInventory $inventory = null` constructor param — populated only when `?include=inventory` is requested. Gated at serialization via null-check (same pattern as `$suppliers`).
- Add computed property `bool $canEditCostPrice` (self-computed in constructor body):
  - `false` if `$isComposite === true`
  - Otherwise `true` if `$defaultSupplier !== null`
- Update `commonDefaultSupplier()` to filter out composite variations before checking supplier consistency:
  - Filter: `!$v->isComposite`
  - Use `array_values()` after `array_filter()` to re-index before accessing `[0]`
  - If all filtered out (empty), return `null`
  - Otherwise apply existing logic to the non-composite subset

### 2. `ProductViewMeta` — Add parent-level composite awareness
**File:** `app/Domain/Catalog/Product/ValueObjects/ProductViewMeta.php`

- Add `?bool $isComposite` param to constructor (from parent's stock item, always available since `stockItem.suppliers` is always eager-loaded)
- Update `resolveCanEditCostPrice()`:
  - If `$isComposite === true` → return `false` (regardless of supplier/variations)
  - Rest of logic unchanged — `commonDefaultSupplier()` now filters composite variations automatically

**File:** `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

- Update `ProductViewMeta` construction at line 142 to pass `$inventory?->isComposite`
- BUT: `$inventory` is only populated with `?include=inventory`. We need the composite flag always.
- Solution: add a `?bool $isComposite` param to `ProductView` constructor (resolved by assembler from the always-loaded `stockItem`, independent of include flags)
- Pass it to `ProductViewMeta`: `new ProductViewMeta($variations, $defaultSupplier, $isComposite)`

**File:** `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

- Add `resolveIsComposite(ProductViewModel $model): ?bool` — reads `$model->stockItem->is_composite` (always available, not gated by include)
- Pass to `ProductView` constructor as `isComposite:` param

### 3. `ProductVariationModelMapper` — Pass isComposite and inventory through
**File:** `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php`

- Add `bool $isComposite = false` param to `toViewDomain()`
- Add `?ProductInventory $inventory = null` param to `toViewDomain()`
- Pass both through to `ProductVariationView` constructor

### 4. `ProductViewAssembler` — Resolve variation isComposite and inventory
**File:** `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

- Add `resolveVariationIsComposite(ProductVariationViewModel $m): bool`
  - Checks `relationLoaded('stockItem')` and `stockItem !== null`
  - Returns `$m->stockItem->is_composite` (always available, zero cost)
  - Returns `false` when no stockItem
- Add `resolveVariationInventory(ProductVariationViewModel $m): ?ProductInventory`
  - Same guards as above
  - Returns `$m->stockItem->toProductInventory()`
  - **Only called when `ProductInclude::Inventory` is in includes** — gated like parent inventory
- Wire both into `resolveVariations()`:
  - `isComposite` always resolved and passed
  - `inventory` only resolved when include requested

### 5. Presentation — Serialize new fields

**File:** `app/Presentation/Http/Api/Resources/ProductVariationResource.php`

- Add `'is_composite' => $variation->isComposite` to `buildData()`
- Add `'can_edit_cost_price' => $variation->canEditCostPrice` to `buildData()`
- Add conditional inventory: `if ($variation->inventory !== null)` → `$data['inventory'] = $variation->inventory->toArray()` (same null-gating pattern as `$suppliers`)

**File:** `app/Presentation/Http/Api/Resources/ProductResource.php`

- Add `'is_composite' => $product->isComposite ?? false` to `baseFields()` (appears in both list and detail views)

**File:** `app/Presentation/Http/Api/Resources/ProductDetailResource.php`

- No structural changes needed — `meta` block already serializes `ProductViewMeta::toArray()` which includes `can_edit_cost_price`
- Parent inventory gating (line 81) unchanged — still uses `hasInclude(ProductInclude::Inventory)`

### 6. Tests

**`tests/Unit/Domain/Catalog/Product/ValueObjects/ProductVariationViewTest.php`:**
- Update `createView()` helper: add `bool $isComposite = false` param
- New tests for `canEditCostPrice`:
  - `false` when composite (even with supplier)
  - `true` when non-composite with supplier
  - `false` when non-composite without supplier
  - `true` when isComposite=false with supplier (explicit non-composite)
- New tests for `commonDefaultSupplier` composite filtering:
  - Skips composite variations, returns common supplier of non-composites
  - Returns null when all variations are composite
  - Preserves existing behaviour when no composites

**`tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewMetaTest.php`:**
- Update `createVariation()` helper: add `bool $isComposite = false` param
- New tests for composite-aware `canEditCostPrice`:
  - `true` when composite variations excluded and remaining share supplier
  - `false` when all variations composite
  - Composite with different supplier name is ignored (doesn't affect result)
  - **`false` when product itself is composite (no variations, has supplier, isComposite=true)** — tests parent-level early-return

---

## Implementation Order

1. **0a: Stop clobbering** — `StockItemFullResponse` (map `isCompositeParent`), `StockItemFull` (`?bool`), `StockItemModelMapper` (conditional write)
2. **0b: Composite flags sync** — Query, UseCase, Job, Repository method, Schedule
3. **Steps 1-2: Domain** — `ProductVariationView` (add `$isComposite`, `$canEditCostPrice`, update `commonDefaultSupplier`), `ProductViewMeta` (add `$isComposite`)
4. **Steps 3-4: Infrastructure** — `ProductVariationModelMapper` + `ProductViewAssembler` (wire isComposite + inventory)
5. **Step 5: Presentation** — `ProductVariationResource`, `ProductResource` (serialize new fields)
6. **Step 6: Tests** — Domain tests, sync tests

## Verification

1. `make test` — existing tests pass (helper defaults keep `$isComposite = false`, `$inventory = null`)
2. New unit tests cover composite filtering and canEditCostPrice logic
3. `make lint` — Pint + PHPStan + PHPArkitect + Deptrac pass
4. Tinker: call `getStockItemsFullByIds` for SKU 1005814, verify domain VO has `isComposite = true`
5. Tinker: dispatch composite flags sync job, verify DB `is_composite` updated for known composites
6. Manual API check: `GET /api/products/{id}?include=variations` — verify `is_composite` and `can_edit_cost_price` on variations
