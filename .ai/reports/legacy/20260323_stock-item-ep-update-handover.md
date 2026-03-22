# Stock Item Extended Property (EP) Update System — Handover Document

**Date:** 2026-03-23
**Scope:** Full ecosystem audit of `UpdateStockItemEpService` and all related EP update infrastructure

---

## 1. Feature Overview

The Stock Item EP Update system is the centralised mechanism for writing Extended Properties (EPs) to Linnworks stock items. EPs are arbitrary key-value metadata fields stored against inventory items in the Linnworks platform. The system is used across multiple business domains — pricing, ShopWired product linking, sale management, product discontinuation, and profit margin tracking.

There are **three distinct EP write paths**:

1. **Service path** — `UpdateStockItemEpService` (implements `IUpdatesEp`) used by backend services and event listeners
2. **AJAX UI path** — `UpdateOne` (Jaxon framework) used for user-initiated EP edits from the browser
3. **Data repair path** — `LwSwEpIdCheck` used for ShopWired-Linnworks EP integrity checks

All three paths ultimately converge on `AlzInvUpdate::epsByKeyVal()` → Linnworks API.

---

## 2. Architecture Diagram

```
                         ┌─────────────────────────────────────────────┐
                         │            EVENT LISTENERS                   │
                         │                                              │
                         │  ProductAddedToSaleListener ─────┐           │
                         │  ProductRemovedFromSaleListener ──┤           │
                         │  ProductHardDiscontinueListener ──┤           │
                         │  ProductReverseHardDiscontinueL. ─┤           │
                         │  UpdateEpProductCreatedListener ──┤           │
                         └───────────────────────────────────┤───────────┘
                                                             │
                         ┌───────────────────────────────────┤───────────┐
                         │            PRICE SERVICES          │           │
                         │                                    │           │
                         │  LinnworksSellingPriceUpdateSvc ───┤           │
                         │  LinnworksRetailPriceUpdateSvc ────┤           │
                         │  UpdatePricesFromFormService ──────┤           │
                         └────────────────────────────────────┤───────────┘
                                                              │
                         ┌────────────────────────────────────┤───────────┐
                         │       SHOP EP WRAPPERS             │           │
                         │                                    │           │
                         │  UpdateEpShopId ───────────────────┤           │
                         │  UpdateEpShopParentId ─────────────┤           │
                         │  UpdateEpSwIsVariant ──────────────┤           │
                         └────────────────────────────────────┤───────────┘
                                                              │
                         ┌────────────────────────────────────┤───────────┐
                         │       SYNC SERVICES                │           │
                         │                                    │           │
                         │  SyncSellingPriceToLinnworksSvc ───┤           │
                         │  ProductProfitCategoryManager ─────┤           │
                         └────────────────────────────────────┤───────────┘
                                                              │
                              PATH 1: SERVICE PATH            │
                                                              ▼
                                ┌─────────────────────────────────────┐
                                │   IUpdatesEp (Contract Interface)   │
                                │                                     │
                                │  update(pkStockItemId, epName,      │
                                │         epValue): void              │
                                └──────────────────┬──────────────────┘
                                                   │
                                                   ▼
                                ┌─────────────────────────────────────┐
                                │   UpdateStockItemEpService          │
                                │   (implements IUpdatesEp)           │
                                │                                     │
                                │   Dependencies:                     │
                                │   - AlzInventory                    │
                                │   - LoggerInterface                 │
                                │   - GetStockItemNameById            │
                                └──────────────────┬──────────────────┘
                                                   │
             PATH 3: DATA REPAIR                   │              PATH 2: AJAX UI
        ┌─────────────────────────┐                │         ┌──────────────────────────┐
        │   LwSwEpIdCheck         │                │         │  UpdateOne (Jaxon AJAX)   │
        │   (integrity repair)    │                │         │  - value transformation   │
        │                         │                │         │  - error handling          │
        └───────────┬─────────────┘                │         │  - dual-write (LW + SW)   │
                    │                              │         │  - success verification    │
                    │     ┌────────────────────┐    │         └────────────┬───────────────┘
                    │     │ UpdateSupplierUrl   │   │                      │
                    │     │ (bypass - no log)   │   │                      │
                    │     └────────┬───────────┘   │                      │
                    │              │                │                      │
                    ▼              ▼                ▼                      ▼
                ┌─────────────────────────────────────────────────────────────┐
                │                 AlzInventory->update()                      │
                │                        ↓                                    │
                │                 AlzInvUpdate                                │
                │                                                             │
                │   oneEp() ──→ epsByKeyVal()                                 │
                │                   │                                         │
                │                   ├─ READ: fetch all current EPs (API call) │
                │                   ├─ COMPARE: current vs new values         │
                │                   ├─ WRITE: only if value differs (API call)│
                │                   └─ CREATE: if EP doesn't exist yet        │
                │                        ↓                                    │
                │              Linnworks API                                   │
                │   (UpdateInventoryItemExtendedProperties /                   │
                │    CreateInventoryItemExtendedProperties)                    │
                └─────────────────────────────────────────────────────────────┘
```

---

## 3. External Integrations

### Linnworks API

| Aspect | Detail |
|--------|--------|
| **API** | Linnworks Inventory API — Extended Properties endpoints |
| **Operations** | Read (`getInventoryItemExtendedProperties`), Update (`updateInventoryItemExtendedProperties`), Create (`createInventoryItemExtendedProperties`) |
| **Auth** | Linnworks API token (managed via `LwApi` / `Linn2\LinnApiClient`) |
| **Credential Storage** | Environment-based config in DI container |
| **SDK** | Custom `AlzApi` wrapper around `Linn2\LinnApiClient` |
| **Rate Limits** | Standard Linnworks API rate limits apply (not explicitly handled in this service) |
| **Data Format** | EP updates sent as key-value pairs; all values cast to `string` |

### API Call Chain

```
AlzInventory::update()          → returns AlzInvUpdate instance
AlzInvUpdate::oneEp()           → calls epsByKeyVal() with single key-value
AlzInvUpdate::epsByKeyVal()     → reads current EPs, compares, creates or updates via Linnworks API
```

**Key behaviour of `epsByKeyVal()`:**
1. **Read-before-write** — Fetches all current EPs for the stock item (always makes a read API call)
2. **Value comparison** — Only adds to update collection if `currentEp->getPropertyValue() !== newValue`
3. **Create-if-not-exists** — If the EP doesn't exist on the stock item, it creates a new one
4. **Force update** — Supports `forceUpdateKeys` parameter to bypass value comparison for specific EP names
5. **Batch support** — Accepts multiple key-value pairs in a single call (but `oneEp()` only passes one)

**Files:**
- `legacy/src/Api/AlzApi/Handler/Endpoint/AlzInventory.php` — main endpoint class
- `legacy/src/Api/AlzApi/Handler/Endpoint/AlzInv/AlzInvUpdate.php` — update operations

### Linnworks Database Schema

EPs are stored in `StockItem_ExtendedProperties`:

| Column | Type | Notes |
|--------|------|-------|
| `fkStockItemId` | GUID | FK to StockItem |
| `ProperyName` | string | EP name (**note:** Linnworks typo "Propery" not "Property") |
| `PropertyValue` | string | EP value |
| `ProperyType` | string | Always `'Attribute'` for standard EPs |

---

## 4. Code Inventory

### Path 1: Service Path (IUpdatesEp)

#### Core Service

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/AlzMvc/Service/Linnworks/Stock/Eps/UpdateStockItemEpService.php` | `UpdateStockItemEpService` | Core EP write service — implements `IUpdatesEp`, delegates to `AlzInventory`, logs success with stock item name |
| `legacy/src/AlzMvc/Service/Linnworks/Stock/Contract/IUpdatesEp.php` | `IUpdatesEp` | Contract interface: `update(string $pkStockItemId, string $epName, mixed $epValue): void` |

#### Direct Dependencies

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzInventory.php` | `AlzInventory` | Linnworks inventory API wrapper |
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzInv/AlzInvUpdate.php` | `AlzInvUpdate` | EP update operations (oneEp, epsByKeyVal) |
| `legacy/src/AlzMvc/Service/Linnworks/Stock/GetStockItemNameById.php` | `GetStockItemNameById` | Resolves stock item name for log messages |
| `legacy/src/AlzMvc/Service/Linnworks/Stock/GetStockItemByPkId.php` | `GetStockItemByPkId` | Fetches stock items by PK ID (cached) |

#### EP-Specific Wrapper Services (in `Stock/Eps/`)

| File | Class | Purpose |
|------|-------|---------|
| `UpdateEpShopId.php` | `UpdateEpShopId` | Type-safe wrapper for `ShopID` EP updates |
| `UpdateEpShopParentId.php` | `UpdateEpShopParentId` | Type-safe wrapper for `ShopParentId` EP updates |
| `UpdateEpSwIsVariant.php` | `UpdateEpSwIsVariant` | Type-safe wrapper for `IsVariant` EP updates (bool→string) |
| `UpdateAllShopEpsService.php` | `UpdateAllShopEpsService` | Orchestrator: updates ShopID + IsVariant + ShopParentId for a stock item |
| `GetShopIdEpService.php` | `GetShopIdEpService` | **Read** operation: fetches ShopID EP (handles `ShopID`/`ShopId` casing) |
| `UpdateSupplierUrlService.php` | `UpdateSupplierUrlService` | Updates `URL` EP — **bypasses IUpdatesEp, calls AlzInventory directly** (no success logging) |

#### Price Update Services

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/AlzMvc/Service/Linnworks/Stock/LinnworksSellingPriceUpdateService.php` | `LinnworksSellingPriceUpdateService` | Updates `SellingPriceGross` + `SellingPriceNet` EPs |
| `legacy/src/AlzMvc/Service/Linnworks/Stock/LinnworksRetailPriceUpdateService.php` | `LinnworksRetailPriceUpdateService` | Updates `RRP` EP + `RetailPrice` stock item field |
| `legacy/src/Mvc/Controller/Pricing/CostPrice/UpdatePricesFromFormService.php` | `UpdatePricesFromFormService` | Form handler: updates URL EP for batch stock items |

#### Sync Services

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/AlzMvc/Service/Shopwired/Product/Updates/SellingPrice/SyncSellingPriceToLinnworksService.php` | `SyncSellingPriceToLinnworksService` | Syncs ShopWired selling prices → Linnworks EPs (`sw_url`, `MetaDescription`, prices). Has comparison-before-write optimisation at the caller level. |
| `legacy/src/AlzMvc/Application/Service/Product/Pricing/Margin/ProductProfitCategoryManager.php` | `ProductProfitCategoryManager` | Syncs profit margins → Linnworks EPs (`custom_label_1`, `ProfitMarginPercent`) |

#### Event Listeners

| File | Class | Event | EPs Updated |
|------|-------|-------|-------------|
| `legacy/src/AlzMvc/Listeners/Product/UpdateEpProductCreatedListener.php` | `UpdateEpProductCreatedListener` | `product.created.event` | `ShopID` |
| `legacy/src/AlzMvc/Listeners/Product/Pricing/ProductAddedToSaleListener.php` | `ProductAddedToSaleListener` | `product.added_to_sale.event` | `is_in_sale` → `'1'` |
| `legacy/src/AlzMvc/Listeners/Product/Pricing/ProductRemovedFromSaleListener.php` | `ProductRemovedFromSaleListener` | `product.removed_from_sale.event` | `last_sale_end_date`, `is_in_sale` → `'0'` |
| `legacy/src/AlzMvc/Listeners/Product/Stock/ProductHardDiscontinueListener.php` | `ProductHardDiscontinueListener` | `product.hard_discontinue.event` | `hard_discontinued_attempt_date` |
| `legacy/src/AlzMvc/Listeners/Product/Stock/ProductReverseHardDiscontinueListener.php` | `ProductReverseHardDiscontinueListener` | `product.hard_discontinue_reverse.event` | `hard_discontinued_attempt_date` |

### Path 2: AJAX UI Path (UpdateOne / Jaxon)

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Api/AlzApi/UniqueProp/Update/UpdateOne.php` | `UpdateOne` | Handles user-initiated EP edits from the browser via Jaxon AJAX |

**Key differences from the Service path:**
- Goes through `LwApi->alzInv()->update()->oneEp()` — bypasses both `IUpdatesEp` and `UpdateStockItemEpService`
- **Value transformation:** Uses `Names::PROPS` to replace empty values with `'0'` for specific EP names (see Business Rules)
- **Error handling:** Has its own try/catch with logger
- **Dual-write:** Can update both Linnworks EP and ShopWired custom field in a single operation via `UpdateSwCf`
- **Success verification:** Reads the EP back after writing to confirm the value matches
- **UI feedback:** Returns a Jaxon `Response` with validation badges for the browser

**Callers:**
- `legacy/src/Alz/Pages/UniqueProps/SelectStockItemCfForm.php` — admin UI form
- `legacy/src/Query/LsqlStock.php` — creates Jaxon function reference

### Path 3: Data Repair Path (LwSwEpIdCheck)

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzInv/LwSwEpIdCheck.php` | `LwSwEpIdCheck` | ShopWired-Linnworks EP ID integrity checking and repair |

Calls `epsByKeyVal()` directly (bypasses both `IUpdatesEp` and `oneEp()`). Uses `forceUpdateKeys` to update `ShopID`/`ShopId` EPs even when values match.

---

## 5. Data Structures

### Event Classes

| Class | File | Fields |
|-------|------|--------|
| `ProductEvent` | `legacy/src/AlzMvc/Events/Product/ProductEvent.php` | `StockItem $stockItem`, `Product $shopwiredProduct` |
| `ProductCreatedEvent` | `legacy/src/AlzMvc/Events/Product/ProductCreatedEvent.php` | Extends `ProductEvent`. Event name: `product.created.event` |
| `LinnworksProductEvent` | `legacy/src/AlzMvc/Events/Product/LinnworksProductEvent.php` | `string $pkStockItemId` |

### Complete EP Name Registry

| EP Name | Type | Domain | Set By |
|---------|------|--------|--------|
| `ShopID` | int (as string) | ShopWired linking | `UpdateEpShopId`, `UpdateEpProductCreatedListener`, `LwSwEpIdCheck` |
| `ShopParentId` | int (as string) | ShopWired linking | `UpdateEpShopParentId` (variants only) |
| `IsVariant` | `'0'`/`'1'` | ShopWired linking | `UpdateEpSwIsVariant` |
| `SellingPriceGross` | float (as string) | Pricing | `LinnworksSellingPriceUpdateService` |
| `SellingPriceNet` | float (as string) | Pricing | `LinnworksSellingPriceUpdateService` |
| `RRP` | float (as string) | Pricing | `LinnworksRetailPriceUpdateService` |
| `URL` | string | Product info | `UpdatePricesFromFormService`, `UpdateSupplierUrlService` |
| `sw_url` | string | Product info | `SyncSellingPriceToLinnworksService` |
| `MetaDescription` | string | Product info | `SyncSellingPriceToLinnworksService` |
| `is_in_sale` | `'0'`/`'1'` | Sale management | `ProductAddedToSaleListener`, `ProductRemovedFromSaleListener` |
| `last_sale_end_date` | datetime string | Sale management | `ProductRemovedFromSaleListener` |
| `hard_discontinued_attempt_date` | `Ymd` string | Discontinuation | `ProductHardDiscontinueListener`, `ProductReverseHardDiscontinueListener` |
| `custom_label_1` | string | Margin tracking | `ProductProfitCategoryManager` (via `GetOneProductMargin::MARGIN_CUSTOM_FIELD_NAME`) |
| `ProfitMarginPercent` | int (as string) | Margin tracking | `ProductProfitCategoryManager` |

**EPs managed via AJAX UI path (`UpdateOne`) — any EP can be edited, but these have special zero-defaulting (via `Names::PROPS`):**

| EP Name | Zero-default | Constant |
|---------|-------------|----------|
| `preorder_date` | empty → `'0'` | `Names::PREORDER_DATE` |
| `preorder_disable` | empty → `'0'` | `Names::PREORDER_DISABLE` |
| `preorder_hide` | empty → `'0'` | `Names::PREORDER_HIDE` |
| `stock_notes` | empty → `'0'` | `Names::STOCK_NOTES` |
| `discontinued` | empty → `'0'` | `Names::DISCONTINUED` |
| `other_stock_status` | empty → `'0'` | `Names::OTHER_STOCK_STATUS` |
| `stock_status_internal` | empty → `'0'` | `Names::STOCK_STATUS_INTERNAL` |

---

## 6. Business Rules

### Core Behaviour
- **All EP values are cast to `string`** before being sent to the Linnworks API — the `IUpdatesEp` interface accepts `int|float|string|null` but `UpdateStockItemEpService` casts to `(string)`.
- **Read-before-write** — Every EP update via `epsByKeyVal()` first reads all current EPs for the stock item (an API call), compares values, and only writes if the value has changed.
- **Create-if-not-exists** — If the target EP doesn't exist on the stock item, `epsByKeyVal()` creates it via `createInventoryItemExtendedProperties` rather than failing.
- **Success logging includes HTML** — `UpdateStockItemEpService` log messages use `<b>` tags wrapping the stock item name and EP details, indicating these logs are rendered in a web UI.
- **No return value** — `update()` is fire-and-forget (void). Callers do not receive confirmation.

### Value Transformation (AJAX UI path only)
- `UpdateOne` applies `Names::PROPS` zero-defaulting: for the 7 EP names listed in `Names::PROPS`, empty string values are automatically replaced with `'0'` before being sent to the API.
- This transformation does **not** apply through the Service path (`UpdateStockItemEpService`). This means the same EP can behave differently depending on which write path is used.

### ShopWired Linking
- When a product is created, `ShopID` is set to the ShopWired product ID.
- `UpdateAllShopEpsService` sets `ShopID`, `IsVariant`, and conditionally `ShopParentId` (only for variant SKUs).
- Variant detection: a SKU is a variant if it does not match the ShopWired product's master SKU.
- **Casing inconsistency:** `GetShopIdEpService` handles both `ShopID` and `ShopId` when reading, but writes always use `ShopID`. `LwSwEpIdCheck` uses `forceUpdateKeys` to handle both casings during repair.

### Pricing
- Selling price updates write both gross and net values (net calculated via `FloatUtils::grossToNet()`).
- Retail price updates write `RRP` EP (gross) and `RetailPrice` stock item field (net) — the latter goes through `IUpdatesStockItemField`, not `IUpdatesEp`.
- `SyncSellingPriceToLinnworksService` compares current values against ShopWired before updating — only writes EPs when values have actually changed at the caller level.

### Sale Management
- Adding to sale: sets `is_in_sale` = `'1'`
- Removing from sale: sets `last_sale_end_date` to current datetime and `is_in_sale` = `'0'`

### Discontinuation
- Both hard discontinue and reverse-hard-discontinue set `hard_discontinued_attempt_date` to today's date (`Ymd` format).
- The EP records the *attempt* date regardless of success/failure of the wider discontinuation process.

---

## 7. Configuration

### DI Container Registration

| Binding | Implementation | File |
|---------|---------------|------|
| `IUpdatesEp::class` | `autowire(UpdateStockItemEpService::class)` | `legacy/src/AlzMvc/Core/Container/Api/Linnworks/lw_autowire.php` |
| `AlzInventory::class` | `autowire()` | `legacy/src/AlzMvc/Core/Container/Api/Linnworks/linnworks.php` |
| `GetStockItemNameById::class` | `autowire()` | `legacy/src/AlzMvc/Core/Container/Api/Linnworks/linnworks.php` |
| `IUpdatesPrice::class` | `get(LinnworksRetailPriceUpdateService::class)` | `legacy/src/AlzMvc/Core/Container/Other/prices.php` |
| `UpdateOne::class` | registered in Jaxon container | `legacy/src/AlzMvc/Core/Container/Other/jaxon.php:74` |

### Event Listener Registration

File: `legacy/src/AlzMvc/Core/Container/Event/listeners.php`

```php
ProductAddedToSaleEvent::NAME   => get(ProductAddedToSaleListener::class)
ProductRemovedFromSaleEvent::NAME => get(ProductRemovedFromSaleListener::class)
ProductHardDiscontinueEvent::NAME => get(ProductHardDiscontinueListener::class)
ProductHardDiscontinueReverseEvent::NAME => get(ProductReverseHardDiscontinueListener::class)
```

Event dispatcher setup: `legacy/src/AlzMvc/Core/Container/Event/event_service.php`

---

## 8. Known Issues & Technical Debt

### Design Inconsistencies

1. **Three independent EP write paths** — The Service path (`UpdateStockItemEpService`), AJAX UI path (`UpdateOne`), and data repair path (`LwSwEpIdCheck`) all write EPs but with different behaviours (logging, value transformation, error handling, verification). A migration should consolidate these into a single service.

2. **`UpdateSupplierUrlService` bypasses `IUpdatesEp`** — It calls `AlzInventory->update()->oneEp()` directly instead of going through `UpdateStockItemEpService`. This means it has no success logging and doesn't follow the established pattern.

3. **Value transformation inconsistency** — `UpdateOne` (AJAX path) applies `Names::PROPS` zero-defaulting for 7 EP names, but `UpdateStockItemEpService` (Service path) does not. The same EP can be written differently depending on which path is used.

4. **Mixed responsibilities in `Eps/` directory** — `GetShopIdEpService` is a read operation sitting alongside write services. The directory implies "EP operations" but the naming doesn't distinguish read vs write.

5. **`ShopID` casing inconsistency** — The EP has been written as both `ShopID` and `ShopId` historically. `GetShopIdEpService` handles both on read, but writes always use `ShopID`. Legacy data may still have the `ShopId` variant.

### Error Handling

6. **No error handling in `UpdateStockItemEpService`** — Exceptions from the Linnworks API bubble up unhandled. Each caller handles (or doesn't handle) errors independently. By contrast, `UpdateOne` has its own try/catch.

7. **`GetStockItemNameById` can return null** — The success log message would contain the text "null" if the stock item name can't be resolved. Cosmetic issue in logs only.

### Performance

8. **Read-before-write overhead** — Every `oneEp()` call triggers a read of ALL current EPs for the stock item via the Linnworks API, even if the value hasn't changed. The write is conditional (only if values differ), but the read always occurs. Services that update multiple EPs on the same stock item (e.g., `LinnworksSellingPriceUpdateService` updating both `SellingPriceGross` and `SellingPriceNet`) make two separate read calls when a single batch call via `epsByKeyVal()` would suffice.

9. **Batch capability exists but is underused** — `epsByKeyVal()` supports multi-key updates in a single call with a single read, but the `IUpdatesEp` interface only exposes single-EP writes via `oneEp()`. A migration could expose batch updates to reduce API calls.

### Architectural Notes

10. **HTML in log messages** — The success message in `UpdateStockItemEpService` uses `<b>` tags, coupling the logging output to a web-rendered context. Standard log aggregation tools would show raw HTML.

11. **Type erasure** — All EP values are stored as strings regardless of their semantic type (bool, int, float, date). Consumers must know the expected type and cast accordingly.

12. **Commented-out code** — `GetShopIdByPkStockId.php:50` has a commented-out call to `updateEpShopId->updateEp()`, indicating historical intent to update EPs from this location.
