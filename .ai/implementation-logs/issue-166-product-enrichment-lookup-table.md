# Implementation Log: Issue #166 - Product Enrichment Lookup Table

**Issue**: feat(mixpanel): Add product_enrichment lookup table with category and supplier data
**Branch**: `feature/166-feat-mixpanel-add-product_enrichment-lookup-table-with-category-and-supplier-data`
**Plan**: `.ai/plans/2026-01-28_166-product-lookup-table-mixpanel.md`

---

## Decision Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Supplier persistence strategy | Delete/re-insert (like extended properties) | Catches supplier removals from Linnworks |
| SKU filtering | Only SKUs with ShopWired match | Per plan - orphan SKUs excluded from lookup table |
| Supplier selection | DISTINCT ON with alphabetical ordering | Deterministic selection when multiple defaults exist |

---

## Progress

### Phase 1: Add Category to StockItem Sync
- [ ] Migration: add category columns to stock_items
- [ ] Domain: add categoryId/categoryName to StockItem VO
- [ ] Infrastructure: map category in StockItemFullResponse
- [ ] Infrastructure: update StockItemModelMapper
- [ ] Infrastructure: update StockItemModel docblock

### Phase 2: Add Supplier Table
- [ ] Migration: create stock_item_suppliers table
- [ ] Update InventoryClient dataRequirements
- [ ] Create SupplierResponse DTO
- [ ] Create StockItemSupplier domain VO
- [ ] Update StockItemFullResponse with suppliers
- [ ] Update StockItem domain VO with suppliers array
- [ ] Create StockItemSupplierModel
- [ ] Create StockItemSupplierMapper
- [ ] Update EloquentStockItemRepository

### Phase 3: ProductLookupTableProvider
- [ ] Create ProductLookupTableProvider
- [ ] Create SyncProductLookupTableJob
- [ ] Register in MixpanelServiceProvider
- [ ] Add config entry
- [ ] Schedule job

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
