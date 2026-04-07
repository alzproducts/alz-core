# Enrich Product Supplier Data via Eager Loading

## Context

The `?include=suppliers` API path currently uses a `ProductSupplierFactory` that loads **every supplier for every SKU in the database** into memory via raw SQL on each request — then only looks up the handful needed for the current page. This was built before the `ProductViewModel.stockItem()` relationship existed (April 1 vs April 3). The Eloquent relationship chain (`ProductViewModel → stockItem → suppliers`) now enables scoped eager loading, matching the pattern already used for `?include=inventory` and `?include=stock`.

Additionally, `ProductSupplier` only exposes 3 of 13 available fields (name, price, isDefault) — missing procurement and pricing data.

## Changes

### 1. Enrich `ProductSupplier` VO — add 8 fields

**File:** `app/Domain/Catalog/Product/ValueObjects/ProductSupplier.php`

Change existing `purchasePrice` from `?float` to `?Money`. Add nullable constructor params with `= null` defaults after existing 3 (defaults required — the factory and 14 test sites still construct with only 3 args):
- `?string $code = null` — supplier MPN/part number
- `?int $leadTime = null` — days
- `?int $supplierMinOrderQty = null`
- `?int $supplierPackSize = null`
- `?Money $minPrice = null` — historical minimum cost
- `?Money $maxPrice = null` — historical maximum cost
- `?Money $averagePrice = null` — historical average cost
- `?float $averageLeadTime = null` — historical average lead time (days, not money)

All `Money` fields are `Money::exclusive()` since supplier prices are always tax-exclusive. Follows the same convention as `ProductView` and `StockItemSupplier`.

**Design note:** `ProductSupplier` deliberately overlaps with `StockItemSupplier` (Inventory domain). They're separate projections for different bounded contexts — `ProductSupplier` is a catalog API view that must never expose internal IDs (`stockItemId`, `supplierId`), while `StockItemSupplier` carries full inventory context and may gain enrichment fields independently.

Update `toArray()` to include all new fields with snake_case keys, serialising Money via `->amount()`.

### 2. Add `toProductSupplier()` to `StockItemSupplierModel`

**File:** `app/Infrastructure/Linnworks/Models/StockItemSupplierModel.php`

New method mapping model fields → enriched `ProductSupplier` VO. Follows established pattern: `StockItemModel::toProductInventory()` / `toProductStock()`.

Monetary fields use `Money::exclusive()` (same as `StockItemSupplierModel::toDomain()`). The existing `purchasePrice` changes from `?float` to `?Money` — the factory's construction (`(float) $row->purchase_price`) must be updated to use `Money::nonZeroOrNull($row->purchase_price, TaxType::Exclusive)` to remain compatible.

### 3. Eager load `stockItem.suppliers` in repository

**File:** `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

Update `relationsForIncludes()` (~line 449):
- Add `ProductInclude::Suppliers` to the `$needsStockItem` check
- Add `'stockItem.suppliers'` when Suppliers include is requested

```php
$needsStockItem = \in_array(ProductInclude::Inventory, $includes, true)
    || \in_array(ProductInclude::Stock, $includes, true)
    || \in_array(ProductInclude::Suppliers, $includes, true);

if ($needsStockItem) {
    $relations[] = 'stockItem';
}

if (\in_array(ProductInclude::Suppliers, $includes, true)) {
    $relations[] = 'stockItem.suppliers';
}
```

### 4. Replace factory with eager-loaded relation in assembler

**File:** `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

- Remove `ProductSupplierFactory` from constructor (line 45)
- Replace `resolveSuppliers()` to read from `$model->stockItem->suppliers` eager-loaded collection, mapping each via `$s->toProductSupplier()`
- Method becomes `static` (matches `resolveInventory` / `resolveStock` pattern)
- Guard on `relationLoaded('stockItem')` + `relationLoaded('suppliers')`
- Products without SKU / without Linnworks match → return `[]`

### 5. No changes needed

- **`ProductDetailResource`** — already calls `$s->toArray()`, picks up new fields automatically
- **`ProductSupplierFactory`** — keep for now, `UpdateCostPriceBySupplierUseCase` still depends on it via `ProductSupplierLookupInterface`. Update `groupBySkuRows()` to construct `Money::nonZeroOrNull()` for `purchasePrice` (was raw float cast)
- **`ShopwiredServiceProvider`** — factory registration stays for use case consumer
- **`ProductView`** — `findDefaultSupplierName()` uses `$s->supplierName` only, unaffected

## Future cleanup (separate PR)

- Refactor `UpdateCostPriceBySupplierUseCase` to query `StockItemSupplierModel` directly instead of using the global factory
- Remove `ProductSupplierFactory`, `ProductSupplierLookupInterface`, and service provider binding

## Verification

1. `make lint` — PHPStan will catch any typing issues from the VO changes
2. `make test` — run full suite
3. Manual: `GET /api/products/{id}?include=suppliers` — verify new fields appear in response
4. Manual: `GET /api/products?include=suppliers` — verify list endpoint works (no N+1)
5. Verify `UpdateCostPriceBySupplierUseCase` still works — it uses the factory (unchanged)
