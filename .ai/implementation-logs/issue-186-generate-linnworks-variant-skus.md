# Implementation Log: Issue #186 - Generate Linnworks Variant SKUs

**GitHub Issue**: #186
**Plan Document**: .ai/plans/2026-01-30_186-generate-linnworks-variant-skus.md
**Status**: In Progress (Blocked)
**Started**: 2026-01-30
**Completed**: —

## Overview

Console command to bulk-create Linnworks inventory items from ShopWired variations that lack SKUs. Generates SKUs, creates Linnworks items with supplier/image/extended properties, and writes SKUs back to ShopWired.

---

## Decision Log

### 2026-01-30
- **Decision**: Use Redis distributed locking for SKU generation
- **Why**: Prevents race conditions when multiple processes generate SKUs concurrently
- **Tradeoff**: Adds Redis dependency, but we already use Redis for caching

### 2026-01-30
- **Decision**: Per-variation transactions with rollback on failure
- **Why**: Partial success is acceptable - some variations may fail while others succeed
- **Tradeoff**: More complex error handling, but better UX than all-or-nothing

### 2026-01-31
- **Decision**: Separate `DATA_REQUIREMENTS_BY_IDS` constant for GetStockItemsFullByIds
- **Why**: `Pricing` DataRequirement causes 400 error on this endpoint (not supported)
- **Tradeoff**: Two constants to maintain, but necessary for API compatibility

### 2026-01-31
- **Decision**: Cost price sentinel value handling in VariationPriceResolver
- **Why**: ShopWired uses -1.0 = inherit parent, 0.0 = unknown (not valid cost prices)
- **Tradeoff**: Domain logic knows about ShopWired-specific values

### 2026-01-31
- **Decision**: 1-based to 0-based image index conversion in VariationImageResolver
- **Why**: ShopWired stores imageIndex as 1-based (matching UI), arrays are 0-based
- **Tradeoff**: None - necessary fix

---

## Critical Issues Discovered

### BLOCKER: Linnworks Soft-Delete SKU Collision

**Problem**: Linnworks soft-deletes inventory items. When calling `AddInventoryItem` with a SKU that matches a soft-deleted item:
- API returns **204 success**
- Item is **NOT created** (or silently updates the deleted one)
- No error is thrown

**Why this is critical**:
- `GetNewItemNumber` returns next sequential SKU (e.g., "1005821")
- If "1005821" was previously deleted, creation silently fails
- `GetStockItemIdsBySKU("1005821")` returns **not found** for soft-deleted items
- We can't detect the collision before or after creation via standard APIs

**Attempted solutions**:
- Pre-check with `GetStockItemIdsBySKU` → Doesn't return soft-deleted items ❌
- Loop with increment → Same problem, can't detect soft-deleted ❌

**Required solution**: Query Linnworks raw database via SQL endpoint (available in legacy codebase)

**Handoff created**: `.ai/handoffs/linnworks-sql-query-analysis.md`

---

### BLOCKER: CreateStockSupplierStat Silent Failure

**Problem**: `CreateStockSupplierStat` returns HTTP 204 (success) but doesn't actually create the supplier linkage.

**Evidence**:
```
[12:07:45] Linnworks API response {"endpoint":"/api/Inventory/CreateStockSupplierStat","status":204,"body_length":0}
[12:07:47] GetStockItemsFullByIds response: "Suppliers":[]  // Empty!
```

**Impact**: Items created without supplier, breaking inventory tracking.

**Required solution**: Post-creation verification that checks `Suppliers` array is non-empty, throw exception if empty to trigger rollback.

---

## Fixed Issues

### Image Index Off-by-One (FIXED - commit 94d2bbc)
- ShopWired stores 1-based imageIndex (matching UI display)
- Code used it directly as 0-based array index
- Fix: `$arrayIndex = $variation->imageIndex - 1`

### GetStockItemsFullByIds DataRequirements (FIXED - commit 257898a)
- `Pricing` requirement not supported by GetStockItemsFullByIds (unlike GetStockItemsFull)
- Caused 400 Bad Request
- Fix: Separate `DATA_REQUIREMENTS_BY_IDS` constant without Pricing

### isVariationParent Nullable (FIXED - commit 257898a)
- GetStockItemsFullByIds doesn't return `isVariationParent` field
- Fix: Made nullable with default null in StockItemFullResponse

### AddImageToInventoryItem Double-Wrapping (FIXED - commit 257898a)
- Transport wraps data in `request` parameter
- Code also wrapped, causing `{"request":{"request":{...}}}`
- Fix: Removed inner wrapper from InventoryUpdateClient

### TaxRate Type Mismatch (FIXED - commit 257898a)
- Linnworks expects -1.0 (float) for "use default tax rate"
- We sent -1 (int)
- Fix: Explicitly use -1.0

---

## Deviations from Plan

- **Extended properties endpoint**: Uses `ProperyName` (typo in Linnworks API, not our bug)
- **GetStockItemsFullByIds**: Needed separate DataRequirements constant (not anticipated)
- **Cost price resolution**: Required domain service for ShopWired sentinel values (not in original plan)

---

## Blockers / Open Questions

- [ ] **BLOCKER**: Implement Linnworks SQL query endpoint to detect soft-deleted SKUs
- [ ] **BLOCKER**: Add post-creation verification for supplier linkage
- [ ] Investigate why CreateStockSupplierStat silently fails (wrong field names? missing required field?)
- [x] ~~Image index off-by-one~~ Fixed
- [x] ~~GetStockItemsFullByIds DataRequirements~~ Fixed
- [x] ~~Cost price sentinel values~~ Fixed

---

## Progress Tracking

### Phase 1-3: Infrastructure ✅
- [x] LockManagerInterface + CacheLockManager
- [x] LockAcquisitionException
- [x] ServiceProvider binding

### Phase 4: Domain Resolvers ✅
- [x] VariationPriceResolver (with cost price sentinel handling)
- [x] VariationImageResolver (with 1-based to 0-based fix)

### Phase 5: Linnworks Read Endpoints ✅
- [x] getStockItemBySku
- [x] getStockItemFull
- [x] getStockItemsFullByIds

### Phase 6: Linnworks Write Endpoints ✅ (API calls work, validation incomplete)
- [x] addInventoryItem
- [x] createSupplierStat (⚠️ silently fails)
- [x] addExtendedProperty
- [x] addImage
- [x] deleteInventoryItem

### Phase 7: Application UseCase ✅
- [x] GenerateVariantSkusUseCase
- [x] LinnworksStockItemCreatorService
- [x] GenerateStockItemFromVariationService

### Phase 8: Console Command ✅
- [x] inventory:generate-variant-skus command
- [x] Manual testing (revealed blockers)

### Phase 9: Response Validation 🚧
- [ ] SQL query endpoint for soft-delete detection
- [ ] Post-creation verification for all write operations
- [ ] Unit tests for new validation logic

---

## Technical Notes

### Linnworks API Quirks
1. **204 = success for write operations** (AddInventoryItem, CreateStockSupplierStat, etc.)
2. **Silent failures**: 204 doesn't guarantee the operation actually succeeded
3. **Soft-deletes**: Items "deleted" via UI are soft-deleted, not removed from database
4. **DataRequirements**: Different endpoints support different requirements (Pricing not universal)
5. **Response inconsistency**: `ItemExtendedProperties` vs `ExtendedProperties` varies by endpoint

### ShopWired Data Quirks
1. **imageIndex**: 1-based (UI display order), not 0-based array index
2. **costPrice**: -1.0 = inherit parent, 0.0 = unknown/null, >0 = valid
3. **price**: null = inherit parent, 0.0 = valid (removed from sale)

---

## PR Notes

_Will complete after blockers resolved_

### What
Bulk-create Linnworks inventory items from ShopWired variations

### Why
Staff adding 30-40 variations to a product need automated Linnworks item creation

### Key Decisions
- Redis distributed locking for SKU generation
- Per-variation transactions with rollback
- Domain resolvers for price/image inheritance

### Testing
- Unit tests for services
- Manual testing revealed critical API issues (documented above)
