# StockItem Model Property Audit

**Source:** `Linnworks\Model\StockItem\StockItem` (extends `Linnworks\BaseExt\Original\AbstractStockItem`)
**Date:** 2026-01-15
**Purpose:** Migration planning - identify active vs removal candidate properties

---

## Summary

| Classification | Count | Percentage |
|---------------|-------|------------|
| ESSENTIAL (10+) | 2 | 6% |
| IMPORTANT (3-9) | 2 | 6% |
| LOW USE (1-2) | 10 | 31% |
| UNUSED (0) | 18 | 56% |
| **Total** | **32** | **100%** |

---

## ESSENTIAL (10+ usages)

Core properties fundamental to business logic.

| Property | PHP Type | Usage Count | Key Usages |
|----------|----------|-------------|------------|
| ItemNumber | `string` | 28+ | SKU identifier used across product creation, updates, pricing, discontinuation, form handling |
| StockItemId | `string` (GUID) | 25+ | Primary Linnworks identifier for API calls, EPs, pricing updates, event handling |

---

## IMPORTANT (3-9 usages)

Regularly used properties in business operations.

| Property | PHP Type | Usage Count | Key Usages |
|----------|----------|-------------|------------|
| ItemTitle | `string` | 4 | PrefillData, CustomFieldsGroup, SlackMessageProductCreatedListener, GetStockItemNameById |
| PurchasePrice | `float` | 3 | PrefillData, SlackMessageProductCreatedListener, UpdateOneCostPriceService |

---

## LOW USE (1-2 usages)

Edge case properties with limited but active usage.

| Property | PHP Type | Usage Count | Key Usages |
|----------|----------|-------------|------------|
| Quantity | `int` | 2 | SlackMessageProductCreatedListener, AlzOrderUpdate |
| EPs | `Collection\|ItemExtendedProperty[]` | 2 | CustomFieldsGroup, LwSwEpId |
| MinimumLevel | `int` | 1 | PrefillData |
| RetailPrice | `float` | 1 | GetGrossRrp |
| TaxRate | `float` | 1 | GetTaxRateBySku |
| CategoryId | `string` (GUID) | 1 | Categories.php |
| Height | `float` | 1 | PrefillData |
| Width | `float` | 1 | PrefillData |
| Depth | `float` | 1 | PrefillData |
| Weight | `float\|null` | 1 | StockSection |

---

## UNUSED (0 usages) - Removal Candidates

Properties with zero usages across the codebase.

| Property | PHP Type | Notes |
|----------|----------|-------|
| ItemDescription | `string` | Full product description |
| InOrder | `int` | Quantity on purchase orders |
| Due | `int` | Quantity due from suppliers |
| Available | `int` | Available stock (Quantity - InOrder) |
| IsCompositeParent | `bool\|null` | Bundle/kit parent flag |
| BarcodeNumber | `string` | EAN/UPC barcode (commented out in PrefillData) |
| MetaData | `string` | Serialized metadata |
| isBatchedStockType | `bool` | Batch tracking flag |
| PostalServiceId | `string` (GUID) | Default shipping service |
| PostalServiceName | `string` | Shipping service name |
| CategoryName | `string` | Category display name |
| PackageGroupId | `string` (GUID) | Package dimensions group |
| PackageGroupName | `string` | Package group name |
| CreationDate | `string\|null` | Item creation timestamp |
| InventoryTrackingType | `int` | Tracking type enum |
| BatchNumberScanRequired | `bool` | Batch scan flag |
| SerialNumberScanRequired | `bool` | Serial scan flag |
| StockItemIntId | `int` | Integer ID (commented out usage) |

---

## Migration Recommendations

### 1. Core Schema (Must Keep)

```
stock_items:
  - stock_item_id: UUID (PRIMARY KEY)
  - item_number: VARCHAR(50) (SKU, UNIQUE INDEX)
  - item_title: VARCHAR(255)
  - purchase_price: DECIMAL(10,2)
```

### 2. Extended Attributes (Keep if Dimensionality Needed)

```
stock_item_attributes:
  - quantity: INTEGER
  - minimum_level: INTEGER
  - retail_price: DECIMAL(10,2)
  - tax_rate: DECIMAL(5,2)
  - category_id: UUID (FK to categories)
  - height: DECIMAL(8,2)
  - width: DECIMAL(8,2)
  - depth: DECIMAL(8,2)
  - weight: DECIMAL(8,3) NULLABLE
```

### 3. Safe to Remove

The 18 unused properties can be safely excluded from the new schema:
- Shipping-related: `PostalServiceId`, `PostalServiceName`
- Package-related: `PackageGroupId`, `PackageGroupName`
- Tracking-related: `InventoryTrackingType`, `BatchNumberScanRequired`, `SerialNumberScanRequired`, `isBatchedStockType`
- Metadata: `MetaData`, `ItemDescription`, `BarcodeNumber`
- Stock levels: `InOrder`, `Due`, `Available` (can be calculated)
- Timestamps: `CreationDate`
- Legacy: `StockItemIntId`, `IsCompositeParent`, `CategoryName`

### 4. Type Improvements

| Current | Recommended | Rationale |
|---------|-------------|-----------|
| `string` StockItemId | `UUID` type | Proper GUID handling |
| `float` prices | `DECIMAL(10,2)` | Monetary precision |
| `float` dimensions | `DECIMAL(8,2)` | Measurement precision |
| `float` tax_rate | `DECIMAL(5,2)` | Percentage precision |
| Individual dimensions | `Dimensions` value object | DDD pattern |

---

## Key Files Using StockItem

### High Dependency
- `legacy/src/Mvc/Controller/Pricing/CostPrice/UpdatePricesFromFormService.php`
- `legacy/src/AlzMvc/Listeners/Product/SlackMessageProductCreatedListener.php`
- `legacy/src/Linnworks/Inventory/StockItem/Create.php`
- `legacy/src/NewProduct/Form/PrefillData.php`
- `legacy/src/AlzMvc/Listeners/Product/Stock/ProductHardDiscontinueListener.php`

### Medium Dependency
- `legacy/src/Api/AlzApi/LinkedItem/Product/LwSwLinkId/LwSwEpId.php`
- `legacy/src/Form/Leg/CustomFieldsGroup.php`
- `legacy/src/AlzMvc/Service/Linnworks/Stock/Eps/UpdateAllShopEpsService.php`

---

## Notes

1. **Linnworks API Alignment:** This model maps to the Linnworks API `StockItem` class. Many unused properties exist for API hydration compatibility but aren't consumed in business logic.

2. **Extended Properties (EPs):** The `EPs` collection property bridges to the `ItemExtendedProperty` model for custom field storage.

3. **Commented Code:** `BarcodeNumber` and `StockItemIntId` have commented-out usages in `PrefillData.php` and `StockSupplierStatPost.php` respectively - confirm these are intentionally unused.

4. **Calculated Fields:** `Available` can be calculated as `Quantity - InOrder`, reducing storage needs if these source values are tracked elsewhere.