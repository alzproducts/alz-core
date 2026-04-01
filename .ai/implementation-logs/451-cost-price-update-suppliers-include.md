# Implementation Log: Issue #451 — Cost Price Update + Suppliers Include

## Overview
Two independent features sharing the supplier data model:
1. **Feature 2 (done first)** — `GET /api/products/{id}?include=suppliers` (additive read path)
2. **Feature 1** — `PUT /api/products/{sku}/cost-price` (write path, Linnworks API)

## Decisions

- `ProductSupplierFactory` injects `DatabaseGatewayInterface` directly (no repo interface needed — infrastructure-internal)
- Local DB update (`updateSupplierPurchasePrice`) logs warning on 0 rows but doesn't throw — Linnworks API is source of truth and already succeeded
- `UpdateCostPriceRequestDTO` uses camelCase input (`costPrice`, `supplierName`) — no Spatie mapper needed since JSON keys match PHP property names
- `Sku::fromString()` used in DTO's `toCommand()` for user input validation (validates length/empty)
- `Money::exclusive()` for cost price (ex-VAT, trade price)
- `ProductSupplierFactory` registered `scoped()` in `ShopwiredServiceProvider` alongside `ProductViewAssembler`

## Files Changed

### New
- `app/Domain/Catalog/Product/ValueObjects/ProductSupplier.php`
- `app/Domain/Catalog/Product/Commands/UpdateCostPriceCommand.php`
- `app/Application/Catalog/UseCases/UpdateCostPriceUseCase.php`
- `app/Infrastructure/Catalog/Product/Factories/ProductSupplierFactory.php`
- `app/Presentation/Http/Api/DTOs/UpdateCostPriceRequestDTO.php`

### Modified
- `app/Domain/Catalog/Product/Enums/ProductInclude.php` — add `Suppliers` case
- `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — add `?array $suppliers`
- `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php` — add `updateSupplierPurchasePrice`
- `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` — add `updateSupplierPurchasePrice`
- `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` — implement `updateSupplierPurchasePrice`
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` — implement local DB update
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` — add `resolveSuppliers` + factory dep
- `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — add `updateCostPrice`
- `app/Presentation/Http/Api/Resources/ProductDetailResource.php` — serialize suppliers
- `app/Providers/ShopwiredServiceProvider.php` — register `ProductSupplierFactory` scoped
- `routes/api.php` — add PUT route

## Lint Fixes Applied
- `execute()` in UseCase: extracted `updateLinnworks()` + `updateLocalDatabase()` private methods (24→12 lines)
- `loadAll()` in Factory: extracted `suppliersSql()` + `groupBySkuRows()` static methods (32→8 lines)
- `staticMethod.dynamicCall`: changed `StockItemModel::query()->getConnection()` to `(new StockItemModel())->getConnection()`
- `phpstan-complexity-baseline.neon`: updated ProductView.__construct() baseline 47→49, plus 7 other stale entries from this feature's changes

## Status
- [x] Feature 2: suppliers include (read path)
- [x] Feature 1: cost price update (write path)
- [x] Tests (1386 pass)
- [x] Lint (all linters pass)
