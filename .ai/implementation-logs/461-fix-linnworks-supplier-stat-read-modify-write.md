# Implementation Log: Issue #461 — Fix Linnworks UpdateStockSupplierStat Read-Modify-Write

## Status
In progress

## Problem
`UpdateStockSupplierStat` uses PUT semantics — missing keys are cleared. Current code only sends 3 fields, silently wiping all other supplier stat fields on every cost price update.

## Approach
Read-modify-write: fetch existing stats via `GetStockSupplierStatsBulk`, merge new price with `withPurchasePrice()`, send complete 15-field objects.

## Changes Made

### Domain
- `StockItemSupplier` VO: `string $supplierId` → `Guid`, `?float` prices → `?Money`, added 5 new fields (`stockItemId`, `stockItemIntId`, `averageLeadTime`, `supplierMinOrderQty`, `supplierPackSize`), added `withPurchasePrice(Money): self`

### Infrastructure
- `StockItemSupplierResponse::toDomain()`: wraps fields in domain types, passes null for 5 new fields
- `StockSupplierStatResponse` (new): full 15-field Spatie Data DTO for bulk stats endpoint, `toDomain()` populates all fields
- `StockItemSupplierModel`: updated `toDomain()` and `attributesFromDomain()` for new domain types
- `InventoryClient`: added `getStockSupplierStatsBulk()` — GET to `/api/Inventory/GetStockSupplierStatsBulk`, groups by StockItemId
- `UpdateStockSupplierStatRequest`: replaced `fromResolved()` + 3-field payload with `fromDomain()` + 15-field payload; `buildBulkPayload(list<StockItemSupplier>)`
- `InventoryUpdateClient`: replaced `updateBulkSupplierPurchasePrice()` with `updateStockSupplierStats(list<StockItemSupplier>)`

### Application
- `InventoryClientInterface`: added `getStockSupplierStatsBulk(array $stockItemIds): array`
- `InventoryUpdateClientInterface`: replaced old method with `updateStockSupplierStats(array $supplierStats): void`
- `CostPriceBySupplierTransformer`: added `extractStockItemGuids()`, `mergeSupplierPrices()`, removed `buildPriceMap()`
- `UpdateCostPriceBySupplierUseCase`: inserted fetch+merge steps into `performBulkUpdate()`

### Tests
- Updated 4 test files: `StockItemSupplierTest`, `StockItemFullTest`, `StockItemParamsBuilderServiceTest`, `GenerateVariantSkusUseCaseTest` to use `Guid`/`Money` domain types

## Key Decisions
- New VO fields default to `null` — backward compatible with DB model (no migration needed)
- `Money::exclusive()` for all price fields (preserves zero = valid price)
- `Guid::equals()` (case-insensitive) for supplier ID matching in merge
- When API fails entirely: report all resolved commands as failures (conservative approach)
- `buildPriceMap()` removed — replaced by `extractStockItemGuids()` + `mergeSupplierPrices()`

## PR Notes
Fix Linnworks `UpdateStockSupplierStat` PUT semantics by implementing read-modify-write pattern. Previously only 3 of 15 supplier stat fields were sent, silently clearing all others (LeadTime, Code, SupplierBarcode, min/max prices, etc.) on every cost price update.

Now fetches current supplier stats first, merges new price, sends complete 15-field payload. Upgraded `StockItemSupplier` VO to use proper domain types (`Guid`, `Money`).
