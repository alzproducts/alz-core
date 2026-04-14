# Plan: Allow Variants to Be Added to Sales

## Context

The front-end needs to add individual product variants to the sale. The **price update write path already supports variant SKUs** — the blockers are in the sale state detection, reconciliation, and cleanup paths.

Additionally, two pre-existing issues need addressing:
1. **ProductView reads SaleSettings from the wrong source** (DB row instead of custom fields)
2. **Reconciliation drift query is too broad** — checking custom field content causes an infinite loop for ~5 products every hour

## Two-Tier Sale Lifecycle

Sales operate on TWO distinct tiers that are currently tightly coupled:

### Tier 1 — SKU-Level (price operations)
- Set/clear sale price on individual SKUs (master or variant)
- ShopWired API, events, Linnworks price sync, SCD2 history
- **Already works for variants** — no changes needed

### Tier 2 — Product-Level (sale state management)  
- Sale category membership, custom fields, `product_sale_settings` DB row
- Sort order override, Slack notifications, Google feed visibility
- **Assumes only the master product can be "on sale"** — THIS IS WHERE ALL BLOCKERS LIVE

### How Tier 2 Data Flows Today
```
SaleSettings (from front-end)
  → product_sale_settings DB row (via persistSaleState UPSERT)
    → ShopWired custom fields (via AddProductToSaleUseCase reading DB row)
      → Auto-removal triggers (CheckExpiredSalesUseCase reading custom fields)
```

### The Two Situations

**Situation 1: One SKU's sale ends, others continue**
- Only that SKU's sale price should be cleared
- Product stays in sale category, custom fields stay, settings row stays
- **Currently broken**: Any removal with `SaleSettings::forRemoval()` DELETES the `product_sale_settings` row and triggers full product-level cleanup via reconciliation

**Situation 2: ALL SKUs removed from sale → full product cleanup**
- All sale prices cleared
- Product removed from sale category, custom fields cleared, settings row deleted
- **Currently works** for single-SKU products

---

## Pre-existing Issue 1: ProductView SaleSettings Source

### Current Behaviour
`ProductViewAssembler::resolveSaleSettings()` (line 181-188) reads from `product_sale_settings` DB table via `$this->saleSettingsRepo->findByProduct()`.

### Why This Is Wrong
The `product_sale_settings` table is a **write-path staging area**, not a read-path source of truth:

1. **Lifecycle gap**: It gets DELETED on sale removal BEFORE custom fields are cleared on ShopWired — so between deletion and `RemoveProductFromSaleUseCase` running, the API returns no sale settings even though they're still live on ShopWired
2. **Legacy gap**: Products added to sale before this feature was deployed have NO DB row — API returns `null` even though custom fields exist
3. **Staleness**: If someone manually edits custom fields in ShopWired admin, the DB row doesn't update
4. **Extra query**: Each product detail request makes a separate DB query for the settings row

### The Actual Source of Truth
The **ShopWired custom fields** on the product are authoritative. They're what:
- Google Shopping reads for `sale_price_effective_date`
- The storefront displays
- `CheckExpiredSalesUseCase` reads for auto-expiration (it already reads from custom fields, NOT the DB row)
- The drift query checks against

### Proposed Change
Read sale settings from the product's typed custom fields in `ProductViewAssembler`, not from the DB table.

**File**: `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
- Remove `SaleSettingsRepositoryInterface` dependency
- `resolveSaleSettings()` extracts from typed custom fields (`sale_reason`, `sale_date_start`, `sale_date_end`, `sale_comments`, `sale_ends_stock`) — same fields the `ReconcileProductSaleStateUseCase::buildSaleSettingsFromProduct()` already reconstructs from

The VO shape (`SaleSettings::toArray()`) is identical regardless of source — the API contract doesn't change.

### Impact Assessment
- **API consumers**: No change — same `SaleSettings` VO, same `toArray()` shape
- **Timing**: Brief window (~5 min) after adding to sale where custom fields haven't been written yet. But front-end just submitted the settings — it already knows them.
- **Slack listener**: Still reads from DB row for add-to-sale enrichment. Still works — DB row exists during the add flow. For removals, uses `SaleSubmissionContext` snapshot. No change needed.
- **`product_sale_settings` table**: Becomes write-path-only. Used by `persistSaleState()` for staging, and by `AddProductToSaleUseCase` to write custom fields. NOT read for API responses.

### Risk: LOW
Custom fields are already the operational source of truth (expired sales checker uses them). This change aligns the API with how the system actually operates.

---

## Pre-existing Issue 2: Reconciliation Drift Query Too Broad

### The Bug
~5 products get flagged for reconciliation every hour, then the reconciliation "fixes" them, but they reappear next hour. Infinite loop.

### Root Cause
The drift query (`buildSaleStateDriftQuery()` lines 774-809) checks custom field content in the LOCAL database:

**Case 1 — On sale but "incomplete":**
```sql
sale_price > 0 AND sale_price < price
AND (
    NOT in_sale_category
    OR sale_reason IS NULL OR sale_reason = ''   ← THE PROBLEM
)
```

**Case 2 — Not on sale but has "artifacts":**
```sql
(sale_price IS NULL OR sale_price <= 0 OR sale_price >= price)
AND (
    in_sale_category
    OR sale_reason IS NOT NULL AND != ''          ← AND HERE
    OR sale_date_start IS NOT NULL AND != ''
    OR sale_date_end IS NOT NULL AND != ''
    OR sale_comments IS NOT NULL AND != ''
    OR sale_ends_stock IS NOT NULL AND != ''
)
```

### Why It Loops

1. `AddProductToSaleUseCase` writes custom fields to **ShopWired API** but does **NOT sync local DB** afterward
2. Local DB's `custom_fields` JSONB column still has empty `sale_reason`
3. Drift query checks LOCAL `custom_fields->>'sale_reason'` → empty → flags as drift
4. Reconciliation dispatches `AddProductToSaleUseCase` again → writes to API again → no local sync → same state next hour
5. **Infinite loop**

### What Reconciliation SHOULD Check

Reconciliation should only check **hard facts**, not soft/derived data:

| Check | Hard Fact? | Reliable Locally? | Should Include? |
|-------|-----------|-------------------|-----------------|
| Has sale price AND not in category | ✅ | ✅ Both columns in local DB | **YES** |
| Not on sale AND in category | ✅ | ✅ Both columns in local DB | **YES** |
| On sale but missing `sale_reason` | ❌ Soft | ❌ Custom fields lag API writes | **NO** |
| Not on sale but has `sale_comments` | ❌ Soft | ❌ Orphaned fields, no harm | **NO** |

### Proposed Change

**Simplify drift query** — only price ↔ category alignment:

**File**: `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

```sql
-- Case 1: On sale but NOT in sale category
WHERE sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price
  AND NOT (category_ids @> '[saleCategoryId]'::jsonb)

-- Case 2: NOT on sale but still in sale category
OR (sale_price IS NULL OR sale_price <= 0 OR sale_price >= price)
  AND category_ids @> '[saleCategoryId]'::jsonb
```

No custom field checks at all.

**Simplify domain resolver** — remove `$hasSaleCustomFields`:

**File**: `app/Application/Shopwired/SaleManagement/Resolvers/ProductSaleStateResolver.php`

```php
$needsAddToSale = $shouldBeOnSale && !$isInSaleCategory;
$needsRemoveFromSale = !$shouldBeOnSale && $isInSaleCategory;
```

**Note**: No explicit DB sync needed in Add/Remove sale use cases — ShopWired fires `product.updated` webhooks after API writes, which trigger `SyncShopwiredProductJob` and sync the local DB within seconds. The drift query runs 5+ minutes later, well after webhook sync.

### What We Lose
Orphaned custom fields on products that are:
- NOT on sale AND NOT in sale category BUT still have leftover `sale_reason`/`sale_date_end` etc.

**Impact**: None. These fields:
- Don't affect the storefront (no sale price)
- Don't affect Google feeds (no `sale_price` → feed ignores them)
- Don't affect auto-expiration (product not found by `getProductsOnSale()`)
- Are purely cosmetic residue

### Risk: LOW
The only functional change is stopping the infinite reconciliation loop. Orphaned custom field cleanup is lost but was never working reliably anyway (it was causing the loop instead of fixing it).

---

## Barrier Analysis (Variant Support)

### Barrier 1: `Product::isOnSale()` — master-only check
**File**: `app/Domain/Catalog/Product/ValueObjects/Product.php` (via `BasicProductTrait`)
- Used by: `ProductSaleStateResolver`, `UpdateShopwiredAddToSaleJob` Skip middleware
- **Impact**: Variant-only sales invisible to reconciliation

### Barrier 2: `persistSaleState()` — binary add/delete, no partial state
**File**: `UpdateProductSellingPricesUseCase.php:156-173`
- **Impact**: Removing variant A deletes settings needed by variant B

### Barrier 3: `SaleSettings` UPSERT overwrites on every add
**File**: `UpdateProductSellingPricesUseCase.php:168-169`
- **Impact**: Silent metadata corruption

### Barrier 4: `CheckExpiredSalesUseCase` — single-SKU removal
**File**: `CheckExpiredSalesUseCase.php`
- **Impact**: Variant sale prices never auto-expire

### Barrier 5: `getProductsOnSale()` SQL — master column only
**File**: `EloquentProductRepository.php:704-716`

### Barrier 6: `buildSaleStateDriftQuery()` SQL — master column only + custom field noise
**File**: `EloquentProductRepository.php:774-809` (addressed in Pre-existing Issue 2 above)

### ~~Barrier 7: `UpdateLinnworksSaleStateJob` — master `isOnSale()` for per-SKU EP~~ → REMOVED
**Resolved**: `is_in_sale` EP is write-only legacy — nothing reads it. Entire dispatch chain removed in Phase 2.

### Barrier 8: `ProductSaleStateResolver` — uniform SKU state + custom field checks
**File**: `ProductSaleStateResolver.php:34-39` (addressed in Pre-existing Issue 2 above)

---

## What's SAFE Today (no changes needed)

| Component | Why |
|-----------|-----|
| `ProductUpdateController` | Accepts variant SKUs in `skuUpdates` array |
| `UpdateProductSellingPricesUseCase` | Resolves parent from any SKU, processes per-SKU |
| `PriceCommandPreFlightService` | Uses `Product::allSkus()` — includes variations |
| `ProductRetailPricingTransformer` | Builds pricing for master + all variations |
| `PriceUpdateClient` | SKU-level ShopWired API — works for variants |
| `AddProductToSaleUseCase` | Product-level category/custom fields — correct |
| `RemoveProductFromSaleUseCase` | Product-level cleanup — correct |
| Events & Listeners | Per-SKU events already fire for variations |
| Slack notifications | Shows all SKU price changes |
| SCD2 price history | Per-SKU recording |

---

## Proposed Changes (All Phases)

### Phase 0: Fix Pre-existing Issues (no variant dependency)

**0a. ProductView: Read SaleSettings from custom fields**
- File: `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
- Remove `SaleSettingsRepositoryInterface` dependency
- Extract SaleSettings from typed custom fields instead of DB query
- Reuse pattern from `ReconcileProductSaleStateUseCase::buildSaleSettingsFromProduct()` — extract to shared static method or duplicate (small)

**0b. Simplify drift query — price ↔ category only**
- File: `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`
- Remove ALL custom field checks from `buildSaleStateDriftQuery()`

**0c. Simplify domain resolver — remove custom field checks**
- File: `app/Application/Shopwired/SaleManagement/Resolvers/ProductSaleStateResolver.php`
- `needsAddToSale = $shouldBeOnSale && !$isInSaleCategory`
- `needsRemoveFromSale = !$shouldBeOnSale && $isInSaleCategory`
- Remove `hasAnySaleCustomField()` usage entirely

**~~0d. Add DB sync to Add/Remove sale use cases~~ — REMOVED**
- Not needed: ShopWired webhooks (`product.updated`) sync local DB within seconds of API writes
- Drift query runs 5+ minutes later — webhook has already synced by then

### Phase 1: Core Detection (make variants visible)

**1a. Domain: `Product::hasAnySaleActive()` + `allOnSaleSkus()`**
- File: `app/Domain/Catalog/Product/ValueObjects/Product.php`
- `hasAnySaleActive()`: check master via existing `isOnSale()`, then check each variation via `Product::isSaleActive($v->salePrice, $v->price ?? $this->price)` — handles null (inherited) variation prices
- `allOnSaleSkus()`: returns `Sku[]` (matches `allSkus()` pattern) — all SKUs where `isSaleActive()` is true

**1b. Sale State Resolver** — use `hasAnySaleActive()`
- File: `ProductSaleStateResolver.php` → `shouldBeOnSale()` uses `hasAnySaleActive()`

**1c. Repository SQL** — expand for variant sale prices
- File: `EloquentProductRepository.php`
- `getProductsOnSale()`: add `orWhereHas('variations', ...)` checking `sale_price IS NOT NULL AND sale_price > 0 AND sale_price < COALESCE(variation.price, product.price)`
- `buildSaleStateDriftQuery()`: add `EXISTS` subquery for variations with same sale price logic

**1d. `UpdateShopwiredAddToSaleJob` Skip middleware** — use `hasAnySaleActive()`
- File: `UpdateShopwiredAddToSaleJob.php:57`

### Phase 2: Remove Legacy Linnworks Sale State EP (cleanup)

**Decision**: The `is_in_sale` extended property on Linnworks is write-only legacy — nothing reads it. Remove the entire dispatch chain.

**2a. Delete `UpdateLinnworksSaleStateJob`**
- File: `app/Infrastructure/Jobs/Linnworks/UpdateLinnworksSaleStateJob.php` — DELETE

**2b. Remove `dispatchUpdateSaleState` from dispatcher interface + implementation**
- File: `app/Application/Contracts/Shopwired/SaleReconciliationDispatcherInterface.php` — remove method
- File: `app/Infrastructure/Shopwired/Dispatchers/QueuedSaleReconciliationDispatcher.php` — remove method

**2c. Remove per-SKU loop from `ReconcileProductSaleStateUseCase`**
- File: `app/Application/Shopwired/SaleManagement/UseCases/ReconcileProductSaleStateUseCase.php`
- Remove lines 89-91 (per-SKU dispatch loop)
- Remove `SkuSaleStateResult` from `ProductSaleStateResult` if no longer needed
- Evaluate if `SkuSaleStateResult` class can be deleted entirely

**2d. Remove `SkuSaleStateResult` from resolver**
- File: `app/Application/Shopwired/SaleManagement/Resolvers/ProductSaleStateResolver.php`
- Remove `$skuSaleStates` array construction (lines 34-40)
- `ProductSaleStateResult` no longer needs `$skuSaleStates` parameter

**2e. Update tests**
- File: `tests/Unit/Application/Shopwired/SaleManagement/UseCases/ReconcileProductSaleStateUseCaseTest.php` — remove sale state dispatch assertions
- Delete any `UpdateLinnworksSaleStateJob` tests

### Phase 3: Expired Sales Cleanup

**3a. `CheckExpiredSalesUseCase`** — clear ALL on-sale SKUs
- File: `app/Application/Shopwired/SaleManagement/UseCases/CheckExpiredSalesUseCase.php`
- Guard: `$product->allOnSaleSkus() === []` instead of `$product->sku === null || $product->sku === ''`
- `removeSale()`: build array of `SkuPriceUpdateCommand` for ALL `allOnSaleSkus()`, pass to `UpdateProductSellingPricesUseCase::execute()` in one call (not a loop) — single API batch + single reconciliation dispatch

### Phase 4: SaleSettings Guardrails

**4a. `persistSaleState()` — don't overwrite existing settings**
- If `product_sale_settings` row exists and no removal reason → SKIP upsert
- Log at WARNING: "SaleSettings submitted but skipped — existing settings already present for product {id}"

**4b. `persistSaleState()` — don't delete when other SKUs remain on sale**
- **Mechanism**: `persistSaleState()` receives `$skuUpdates` as additional parameter
- Compute which SKUs are being removed (salePrice = 0 or null in commands)
- Get `$product->allOnSaleSkus()` (product already fetched at line 90)
- Filter out SKUs present in removal commands
- If any SKUs remain on sale → preserve settings row, still return `SaleSubmissionContext` for Slack
- If NO SKUs remain → delete settings row (existing behaviour)

---

## Edge Cases

1. **First variant added to sale** — SaleSettings saved normally, custom fields written via reconciliation ✅
2. **Second variant added with different SaleSettings** — Settings skip-logged (4a), price goes through ✅
3. **One variant removed, others stay** — Price cleared, settings row preserved (4b), product stays in category (1b) ✅
4. **Last variant removed** — Full product-level cleanup triggers (4b recognises no SKUs on sale) ✅
5. **End date expires** — All on-sale SKU prices cleared (3a), then full product cleanup ✅
6. **Product with no master SKU, only variant SKUs** — Found by expanded SQL (1c), cleared properly (3a) ✅
7. **Legacy products (no DB row)** — ProductView reads from custom fields (0a) ✅
8. **Reconciliation loop products** — Fixed by removing custom field checks (0b/0c) ✅
9. **Variant with inherited price (null)** — `hasAnySaleActive()` uses `$v->price ?? $this->price` (1a) ✅

## Verification

1. **Unit tests**: `Product::hasAnySaleActive()` and `allOnSaleSkus()` — master-only, variant-only, mixed, inherited price (null variation price), no-sale
2. **Unit tests**: `ProductSaleStateResolver` — variant-on-sale, simplified needsAdd/needsRemove (no custom field checks)
3. **Unit tests**: `CheckExpiredSalesUseCase` — variant expiration, no-master-SKU products, multi-SKU removal
4. **Unit tests**: `persistSaleState()` — don't overwrite existing (4a), don't delete when SKUs remain (4b), delete when last SKU removed
5. **Unit tests**: `ProductViewAssembler` — saleSettings from custom fields, null when no custom fields
6. **Integration**: Full write path: variant sale price → reconciliation → category membership
7. **Integration**: Expired sales: end date triggers → all SKUs cleared → category removed
8. **Smoke test**: Verify the 5 looping products no longer appear in drift query after Phase 0
9. `make lint` and `make test` must pass
