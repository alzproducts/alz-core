# Implementation Log: Issue #166 - Product Enrichment Lookup Table

**Issue**: feat(mixpanel): Add product_enrichment lookup table with category and supplier data
**Branch**: `feature/166-feat-mixpanel-add-product_enrichment-lookup-table-with-category-and-supplier-data`
**Plan**: `.ai/plans/2026-01-28_166-product-lookup-table-mixpanel.md`

---

## Decision Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Created separate `StockItemFull` VO | New domain object instead of modifying `StockItem` | Two API endpoints return different data shapes - `GetInventoryItemById` vs `GetStockItemsFull`. Clean separation avoids breaking existing code. |
| Category columns NOT nullable | Required (defaults to "Default" in Linnworks) | Linnworks always returns category data - simplifies domain model |
| Supplier persistence strategy | Delete/re-insert (like extended properties) | Catches supplier removals from Linnworks |
| Inline mapper in model | `StockItemSupplierModel::attributesFromDomain()` | Simple snake_case conversion - separate mapper class unnecessary |
| SKU filtering | Only SKUs with ShopWired match | Per plan - orphan SKUs excluded from lookup table |
| Supplier selection | DISTINCT ON with alphabetical ordering | Deterministic selection when multiple defaults exist |

---

## Progress

### Phase 1: Add Category to StockItem Sync ✓
- [x] Migration: add category columns to stock_items (`2026_01_28_100000`)
- [x] Domain: created `StockItemFull` VO with categoryId/categoryName
- [x] Infrastructure: updated `StockItemFullResponse` to return `StockItemFull`
- [x] Infrastructure: updated `InventoryClient.iterateStockItemBatches()` return type
- [x] Infrastructure: updated `StockItemModelMapper` for `StockItemFull`
- [x] Infrastructure: updated `StockItemModel` docblock

### Phase 2: Add Supplier Table ✓
- [x] Migration: create stock_item_suppliers table (`2026_01_28_110000`)
- [x] Update InventoryClient dataRequirements (added 'Suppliers')
- [x] Create `SupplierResponse` DTO
- [x] Create `StockItemSupplier` domain VO
- [x] Update `StockItemFullResponse` with suppliers array
- [x] Update `StockItemFull` domain VO with suppliers array + helpers
- [x] Create `StockItemSupplierModel` (with inline mapper)
- [x] Update `EloquentStockItemRepository` (delete/re-insert for suppliers)

### Phase 3: ProductLookupTableProvider
- [ ] Create ProductLookupTableProvider
- [ ] Create SyncProductLookupTableJob
- [ ] Register in MixpanelServiceProvider
- [ ] Add config entry
- [ ] Schedule job

---

## Files Modified (Phase 2)

**New Files:**
- `database/migrations/2026_01_28_110000_create_linnworks_stock_item_suppliers_table.php`
- `app/Domain/Inventory/ValueObjects/StockItemSupplier.php`
- `app/Infrastructure/Linnworks/Responses/SupplierResponse.php`
- `app/Infrastructure/Linnworks/Models/StockItemSupplierModel.php`

**Modified Files:**
- `app/Domain/Inventory/ValueObjects/StockItemFull.php` - Added suppliers array + helpers
- `app/Infrastructure/Linnworks/Responses/StockItemFullResponse.php` - Added suppliers mapping
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` - Added supplier sync
- `app/Infrastructure/Linnworks/Clients/InventoryClient.php` - Added 'Suppliers' to dataRequirements

---

## PR Notes

**Title**: feat(mixpanel): Add product_enrichment lookup table with category and supplier data

**Summary**:
- Adds category fields (ID + name) to Linnworks stock item sync
- Creates new `stock_item_suppliers` table synced from Linnworks API
- Implements `ProductLookupTableProvider` for Mixpanel lookup table enrichment
- Maps SKUs to ShopWired product IDs, categories, and default suppliers

**Test Plan**:
- [ ] Run `SyncLinnworksStockItemsJob`, verify category fields populated
- [ ] Verify supplier data in `stock_item_suppliers` table
- [ ] Run `SyncProductLookupTableJob`, verify Mixpanel upload
- [ ] Confirm only SKUs with ShopWired matches are included
