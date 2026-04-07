# Plan: Add barcode, variation MPN, and default supplier sub-object to product list

## Context

The front-end ProductPicker needs `barcode` (gtin) and default supplier MPN on the product list response. Neither surfaces to the list endpoint today. Additionally, variation `mpn` flows through the full domain chain but the resource omits it.

Rather than adding more flat `default_supplier_xxx` fields to the top level (which duplicates data, loses null signals, and clutters the response), we'll return the default supplier as a full structured sub-object using the existing `ProductSupplier` VO, always loaded.

---

## Changes

### 1. Variation `mpn` — one-line fix

**File:** `app/Presentation/Http/Api/Resources/ProductVariationResource.php`

Add `'mpn' => $variation->mpn,` after line 29 (`gtin`). The full chain already works — DB → view → ViewModel → mapper → `ProductVariationView::$mpn`. Only the resource omits it.

---

### 2. Product `gtin` — thread existing data through 3 layers

The SQL view already selects `p.gtin` (line 59), `ProductViewModel` already has `@property string|null $gtin` (line 26). Just need to pipe it through:

**a) Domain VO** — `app/Domain/Catalog/Product/ValueObjects/ProductView.php`
- Add constructor param `?string $gtin` (after `sku`)
- Add property `public ?Gtin $gtin` — constructed via `Gtin::fromTrusted()` mirroring the `sku` pattern (line 131)

**b) Assembler** — `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
- Add `gtin: $model->gtin,` to the `new ProductView(...)` call (after `sku:`)

**c) Resource** — `app/Presentation/Http/Api/Resources/ProductResource.php`
- Add `'gtin' => $product->gtin?->value,` to `baseFields()` after `sku`

---

### 3. Default supplier sub-object — replace flat string with `ProductSupplier`

**Goal:** `ProductView::$defaultSupplier` becomes `?ProductSupplier` (was `?string`). Always loaded, always available in `baseFields()`. No include needed.

**a) Always eager-load suppliers** — `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

In `relationsForIncludes()` (line 449), always include `stockItem.suppliers` regardless of includes:

```php
// Always load for default supplier derivation
$relations[] = 'stockItem.suppliers';
```

This replaces the conditional block at lines 462-464. The existing `stockItem` load for Inventory/Stock (line 458-459) becomes redundant since `stockItem.suppliers` loads both, but keep it for clarity or remove — minor.

**b) Derive default supplier in assembler** — `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

Add a new private method (not gated by any include):

```php
private static function resolveDefaultSupplier(ProductViewModel $model): ?ProductSupplier
{
    if (! $model->relationLoaded('stockItem') || $model->stockItem === null) {
        return null;
    }

    $default = $model->stockItem->defaultSupplier();

    return $default?->toProductSupplier();
}
```

Uses the existing `StockItemModel::defaultSupplier()` (line 139) and `StockItemSupplierModel::toProductSupplier()`.

Pass to constructor: `defaultSupplier: self::resolveDefaultSupplier($model),`

**c) Update ProductView VO** — `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

- Change property type: `public ?ProductSupplier $defaultSupplier;` (was `?string`)
- Add constructor param: `?ProductSupplier $defaultSupplier = null` (promoted, trailing optional)
- Remove `self::findDefaultSupplierName($this->suppliers)` assignment (line 141)
- Remove `findDefaultSupplierName()` method (lines 149-156) — no longer needed
- The property is now set directly from the constructor param, not derived internally

**d) Expose in resource** — `app/Presentation/Http/Api/Resources/ProductResource.php`

Add to `baseFields()`:

```php
'default_supplier' => $product->defaultSupplier?->toArray(),
```

This outputs the full `ProductSupplier` structure:
```json
{
  "default_supplier": {
    "supplier_name": "Acme Ltd",
    "purchase_price": 10.50,
    "is_default": true,
    "code": "ABC-123",
    "supplier_barcode": "5060...",
    "lead_time": 5,
    "supplier_min_order_qty": 10,
    "supplier_pack_size": 1,
    "min_price": 9.00,
    "max_price": 12.00,
    "average_price": 10.50,
    "average_lead_time": 4.5
  }
}
```

The front-end gets `code` (MPN), `supplier_barcode`, `supplier_name`, and everything else in one structured sub-object.

**e) Full suppliers array stays gated** — `ProductViewAssembler::resolveSuppliers()` (line 234) remains unchanged. The full `suppliers` array on `ProductView` is still only populated when `ProductInclude::Suppliers` is requested (detail endpoint). The `default_supplier` sub-object is independent.

---

## Files modified

| File | Change |
|------|--------|
| `app/Presentation/Http/Api/Resources/ProductVariationResource.php` | Add `mpn` field |
| `app/Domain/Catalog/Product/ValueObjects/ProductView.php` | Add `gtin`, change `defaultSupplier` from `?string` to `?ProductSupplier`, remove `findDefaultSupplierName()` |
| `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` | Pass `gtin`, add `resolveDefaultSupplier()`, pass to constructor |
| `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Always eager-load `stockItem.suppliers` |
| `app/Presentation/Http/Api/Resources/ProductResource.php` | Add `gtin` and `default_supplier` to `baseFields()` |

---

## Verification

1. `make lint` — PHPStan will catch any type mismatches from the `?string` → `?ProductSupplier` change
2. `make test` — existing ProductView tests will need updating for new constructor params
3. Hit `GET /api/products` locally — verify `gtin` and `default_supplier` sub-object appear
4. Hit `GET /api/products/{id}?include=suppliers` — verify full `suppliers` array still works alongside the new `default_supplier`
5. Check a product with no Linnworks match — `default_supplier` should be `null`
