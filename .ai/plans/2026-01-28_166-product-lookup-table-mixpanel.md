# ProductLookupTable Implementation Plan

## Overview

Create a Mixpanel `product_enrichment` lookup table that maps SKUs to:
- `group_identifier` - ShopWired product ID (always parent, even for variants)
- `default_category` - From Linnworks category data
- `default_supplier` - From Linnworks default supplier

**Prerequisites**:
1. Add category fields to Linnworks stock item sync
2. Create new suppliers table with Linnworks supplier data

---

## Phase 1: Add Category to StockItem Sync

**Status**: `categoryId` and `categoryName` already exist in `StockItemFullResponse` (lines 51-52) but are NOT mapped to domain or persisted.

### 1.1 Migration: Add category columns
**File**: `database/migrations/XXXX_add_category_to_linnworks_stock_items.php`

```php
Schema::table('linnworks.stock_items', function (Blueprint $table) {
    $table->string('category_id', 64)->nullable();
    $table->string('category_name', 255)->nullable();
    $table->index('category_id');
});
```

### 1.2 Update Domain StockItem
**File**: `app/Domain/Inventory/ValueObjects/StockItem.php`

Add parameters to constructor:
```php
public ?string $categoryId = null,
public ?string $categoryName = null,
```

### 1.3 Update StockItemFullResponse.toDomain()
**File**: `app/Infrastructure/Linnworks/Responses/StockItemFullResponse.php`

Add to domain mapping (empty strings → null):
```php
categoryId: $this->categoryId !== '' ? $this->categoryId : null,
categoryName: $this->categoryName !== '' ? $this->categoryName : null,
```

### 1.4 Update StockItemModelMapper
**File**: `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php`

Add bidirectional mapping for `category_id` and `category_name`.

### 1.5 Update StockItemModel
**File**: `app/Infrastructure/Linnworks/Models/StockItemModel.php`

Add to docblock: `@property string|null $category_id`, `@property string|null $category_name`

---

## Phase 2: Add Supplier Table

### 2.1 Migration: Create suppliers table
**File**: `database/migrations/XXXX_create_linnworks_stock_item_suppliers_table.php`

```php
Schema::create('linnworks.stock_item_suppliers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('stock_item_id', 64)->index();
    $table->string('supplier_id', 64);
    $table->string('supplier_name', 255);
    $table->string('code', 100)->nullable();
    $table->string('supplier_barcode', 100)->nullable();
    $table->decimal('purchase_price', 12, 4)->nullable();
    $table->boolean('is_default')->default(false);
    $table->integer('lead_time')->nullable();
    $table->string('supplier_currency', 10)->nullable();
    $table->decimal('min_price', 12, 4)->nullable();
    $table->decimal('max_price', 12, 4)->nullable();
    $table->decimal('average_price', 12, 4)->nullable();
    $table->timestampsTz();

    $table->unique(['stock_item_id', 'supplier_id']);
    $table->index(['stock_item_id', 'is_default']); // For lookup table query
});
```

### 2.2 Update InventoryClient dataRequirements
**File**: `app/Infrastructure/Linnworks/Clients/InventoryClient.php` (line ~176)

```php
'dataRequirements' => ['ExtendedProperties', 'StockLevels', 'Pricing', 'Suppliers'],
```

### 2.3 Create SupplierResponse DTO
**File**: `app/Infrastructure/Linnworks/Responses/SupplierResponse.php`

```php
#[MapInputName(PascalCaseMapper::class)]
final class SupplierResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $supplierId,
        public readonly string $supplier,  // Name
        public readonly string $code,
        public readonly ?string $supplierBarcode,
        public readonly float $purchasePrice,
        public readonly bool $isDefault,
        public readonly ?int $leadTime,
        public readonly ?string $supplierCurrency,
        public readonly ?float $minPrice,
        public readonly ?float $maxPrice,
        public readonly ?float $averagePrice,
        public readonly string $stockItemId,
    ) {}
}
```

### 2.4 Create Domain StockItemSupplier VO
**File**: `app/Domain/Inventory/ValueObjects/StockItemSupplier.php`

Readonly class with supplier business fields.

### 2.5 Update StockItemFullResponse
**File**: `app/Infrastructure/Linnworks/Responses/StockItemFullResponse.php`

Add `suppliers` parameter with `#[DataCollectionOf(SupplierResponse::class)]`.

### 2.6 Update Domain StockItem
**File**: `app/Domain/Inventory/ValueObjects/StockItem.php`

Add `public array $suppliers = []` parameter (like `extendedProperties`).

### 2.7 Create StockItemSupplierModel
**File**: `app/Infrastructure/Linnworks/Models/StockItemSupplierModel.php`

Follow `StockItemExtendedPropertyModel` pattern.

### 2.8 Create StockItemSupplierMapper
**File**: `app/Infrastructure/Linnworks/Mappers/StockItemSupplierMapper.php`

Follow `StockItemExtendedPropertyMapper` pattern.

### 2.9 Update EloquentStockItemRepository
**File**: `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php`

Add delete/re-insert for suppliers (same pattern as extended properties, lines 52-73).

---

## Phase 3: ProductLookupTableProvider

### 3.1 Create ProductLookupTableProvider
**File**: `app/Infrastructure/Mixpanel/LookupTables/ProductLookupTableProvider.php`

```php
final readonly class ProductLookupTableProvider implements LookupTableProviderInterface
{
    public function __construct(
        private DatabaseGateway $database,
    ) {}

    public function getTableKey(): string
    {
        return 'product_enrichment';
    }

    public function getSourceName(): string
    {
        return 'Linnworks/ShopWired';
    }

    public function getHeaders(): array
    {
        return ['sku', 'group_identifier', 'default_category', 'default_supplier'];
    }

    public function fetchRows(): array
    {
        // Query joins Linnworks SKUs to ShopWired products
        // EXCLUDES SKUs without ShopWired match (per user decision)
    }
}
```

**SQL Query**:
```sql
SELECT DISTINCT ON (si.item_number)
    si.item_number AS sku,
    COALESCE(p.external_id, pv.product_external_id)::text AS group_identifier,
    si.category_name AS default_category,
    s.supplier_name AS default_supplier
FROM linnworks.stock_items si
LEFT JOIN shopwired.products p ON p.sku = si.item_number
LEFT JOIN shopwired.product_variations pv ON pv.sku = si.item_number
LEFT JOIN linnworks.stock_item_suppliers s
    ON s.stock_item_id = si.stock_item_id AND s.is_default = true
WHERE p.sku IS NOT NULL OR pv.sku IS NOT NULL  -- Exclude orphan SKUs
ORDER BY si.item_number, s.supplier_name NULLS LAST  -- Deterministic supplier selection
```

**Note**: `DISTINCT ON` ensures exactly one row per SKU even if multiple default suppliers exist. The `ORDER BY` ensures deterministic selection (alphabetically first supplier name, with NULL handled gracefully).

### 3.2 Create SyncProductLookupTableJob
**File**: `app/Presentation/Jobs/Mixpanel/SyncProductLookupTableJob.php`

Follow `SyncOrderLookupTableJob` pattern exactly.

### 3.3 Register in MixpanelServiceProvider
**File**: `app/Providers/MixpanelServiceProvider.php`

Add contextual binding for `SyncProductLookupTableJob` → `ProductLookupTableProvider`.

### 3.4 Add Config Entry
**File**: `config/mixpanel.php`

```php
'product_enrichment' => env('MIXPANEL_LOOKUP_TABLE_PRODUCT_ENRICHMENT'),
```

### 3.5 Schedule Job
**File**: `routes/console.php`

Schedule after Linnworks stock sync completes (or hourly).

---

## Files to Create

| File | Type |
|------|------|
| `database/migrations/XXXX_add_category_to_linnworks_stock_items.php` | Migration |
| `database/migrations/XXXX_create_linnworks_stock_item_suppliers_table.php` | Migration |
| `app/Infrastructure/Linnworks/Responses/SupplierResponse.php` | DTO |
| `app/Domain/Inventory/ValueObjects/StockItemSupplier.php` | Value Object |
| `app/Infrastructure/Linnworks/Models/StockItemSupplierModel.php` | Eloquent Model |
| `app/Infrastructure/Linnworks/Mappers/StockItemSupplierMapper.php` | Mapper |
| `app/Infrastructure/Mixpanel/LookupTables/ProductLookupTableProvider.php` | Lookup Provider |
| `app/Presentation/Jobs/Mixpanel/SyncProductLookupTableJob.php` | Queue Job |

## Files to Modify

| File | Change |
|------|--------|
| `app/Domain/Inventory/ValueObjects/StockItem.php` | Add category + suppliers |
| `app/Infrastructure/Linnworks/Responses/StockItemFullResponse.php` | Map category + suppliers to domain |
| `app/Infrastructure/Linnworks/Clients/InventoryClient.php` | Add 'Suppliers' to dataRequirements |
| `app/Infrastructure/Linnworks/Models/StockItemModel.php` | Add category docblock |
| `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php` | Map category fields |
| `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` | Add supplier delete/re-insert |
| `app/Providers/MixpanelServiceProvider.php` | Add contextual binding |
| `config/mixpanel.php` | Add lookup table config |
| `routes/console.php` | Schedule sync job |

---

## Verification

1. **Phase 1**: Run `SyncLinnworksStockItemsJob`, verify `category_name` populated in DB
2. **Phase 2**: Run sync again, verify `stock_item_suppliers` populated with default suppliers
3. **Phase 3**: Run `SyncProductLookupTableJob`, verify Mixpanel lookup table updated

**Test Query** (after phases 1-2):
```sql
SELECT DISTINCT ON (si.item_number)
    si.item_number,
    si.category_name,
    s.supplier_name,
    COALESCE(p.external_id, pv.product_external_id) as shopwired_product_id
FROM linnworks.stock_items si
LEFT JOIN linnworks.stock_item_suppliers s ON s.stock_item_id = si.stock_item_id AND s.is_default
LEFT JOIN shopwired.products p ON p.sku = si.item_number
LEFT JOIN shopwired.product_variations pv ON pv.sku = si.item_number
WHERE p.sku IS NOT NULL OR pv.sku IS NOT NULL
ORDER BY si.item_number, s.supplier_name NULLS LAST
LIMIT 10;
```
