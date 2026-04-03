# Implementation Log: Issue #473 — ProductView Inventory & Stock Includes

## Status: In Progress

## Decisions

- `ProductInventory` and `ProductStock` are self-constructing VOs (primitives in, domain types built internally)
- `ProductInventory` uses try/catch on `Gtin::fromString()` for barcode validation; null on invalid
- `WeightUnit::tryFrom()` falls back to `WeightUnit::Kilogram` if unit is unknown/null
- Dimensions are null if all three floats (h/w/d) are null; constructed otherwise
- `jit` lives only in `ProductStock` (not duplicated in `ProductInventory`) per plan spec
- `catalog.products_view` migration: DROP + full re-CREATE (PostgreSQL requirement for column changes)
- `si` alias already exists in the view — only `p.stock` → `COALESCE(si.quantity, p.stock)` change needed
- Weight tests removed from `ProductViewTest` (weight moved to `ProductInventory`)
- `ProductVariationView` is out of scope — leave as-is per plan

## Files Modified

| File | Action |
|------|--------|
| `database/migrations/2026_04_03_000001_update_catalog_products_view_stock_from_linnworks.php` | CREATE — re-source stock from Linnworks |
| `app/Domain/Catalog/Product/ValueObjects/ProductInventory.php` | CREATE |
| `app/Domain/Catalog/Product/ValueObjects/ProductStock.php` | CREATE |
| `app/Domain/Catalog/Product/Enums/ProductInclude.php` | Add Inventory, Stock cases |
| `app/Domain/Catalog/Product/ValueObjects/ProductView.php` | Remove stock/gtin/weight, add inventory/stock VOs |
| `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` | Add stockItem() HasOne |
| `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Update relationsForIncludes() |
| `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` | Remove old fields, add resolveInventory/resolveStock |
| `app/Presentation/Http/Api/Resources/ProductResource.php` | Remove base fields, add conditional includes |
| `app/Presentation/Http/Api/Resources/ProductDetailResource.php` | Add inventory/stock includes |
| `app/Presentation/Http/Api/DTOs/ListProductsRequestDTO.php` | Update allowedIncludes() |
| `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php` | Remove stock/gtin/weight params from helper |
| `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductInventoryTest.php` | CREATE |
| `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductStockTest.php` | CREATE |

## Lint / Simplify / Sweep Fixes

- `alz.noTryCatchInDomain` — Added `Gtin::tryFromString()` (no try-catch); extracted `normalizeAndValidate()` helper
- Stale baseline entries updated: `ProductView` 50→47, `ProductViewAssembler::toViewDomain` 38→37, `EloquentProductRepository` 838→849, `ProductResource::baseFields` 34→31
- `ProductViewAssembler` refactored to keep under 250-line class limit (combined guard clauses, shorter `resolveInventory`/`resolveStock`)
- `ProductDetailResource::scalarIncludes()` split into `scalarIncludes()` + `linnworksIncludes()` to meet 20-line/complexity-10 limits
- Sweep: removed redundant `readonly` qualifiers from `ProductInventory` and `ProductStock` constructor params (class-level `readonly` covers them)

## PR Notes

**Title:** feat(catalog): expose Linnworks inventory & stock via ProductView includes (#473)

**Body:**
Adds `?include=inventory` and `?include=stock` to both list and detail product endpoints, backed by Linnworks as the source of truth.

- New `ProductInventory` and `ProductStock` VOs (self-constructing from Linnworks primitives)
- `HasOne` eager-load from `ProductViewModel` → `StockItemModel` via `sku = item_number`
- `catalog.products_view` stock column re-sourced to `COALESCE(si.quantity, p.stock)`
- Removed `gtin`, `stock` (int), `weight` from base `ProductView` and API response
- `Gtin::tryFromString()` added (no-throw barcode validation for domain use)
