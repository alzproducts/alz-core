# Implementation Log: Issue #121 - Linnworks StockItem Sync

**Issue**: #121
**Branch**: `feature/121-implement-linnworks-stockitem-sync-with-daily-full-refresh-strategy`
**Started**: 2026-01-16
**Plan**: `.ai/plans/2026-01-15_121-linnworks-stock-item-sync.md`

---

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-01-16 | Follow ShopWired customer sync pattern exactly | Proven pattern with generator→buffer→flush, exception handling, scheduling |
| 2026-01-16 | Enhance existing `StockItem` VO rather than create new | VO already exists with core fields; add `stockItemId` and missing fields |
| 2026-01-16 | Permissions in schema migration, not separate | Set DEFAULT PRIVILEGES in schema creation so tables inherit automatically |
| 2026-01-16 | Nullable fields for source fidelity | NULL = "API didn't provide" vs 0 = "API explicitly returned zero". Enables data quality analysis |
| 2026-01-16 | Remove `is_composite` boolean index | Boolean indexes rarely help at ~10k rows scale |

---

## Implementation Progress

### Phase 1: Domain Layer ✅
- [x] Enhance `StockItem` VO with `stockItemId` field
- [x] Create `StockItemExtendedProperty` VO
- [x] Create `Dimensions` VO
- [x] Create `Weight` VO
- [x] Create `WeightUnit` enum

### Phase 2: Database Migrations ✅
- [x] Create `linnworks` schema with Supabase permissions
- [x] Create `linnworks.stock_items` table (nullable fields for source fidelity)
- [x] Create `linnworks.stock_item_extended_properties` table with FK cascade

### Phase 3: Infrastructure - Client
- [ ] Add `iterateStockItemBatches()` to `InventoryClientInterface`
- [ ] Implement in `InventoryClient`
- [ ] Create `GetStockItemsFullResponse` DTO

### Phase 4: Infrastructure - Repository
- [ ] Create `StockItemRepositoryInterface` in Application/Contracts
- [ ] Create `StockItemModel` Eloquent model
- [ ] Create `StockItemExtendedPropertyModel` Eloquent model
- [ ] Create `EloquentStockItemRepository`
- [ ] Create `StockItemModelMapper`

### Phase 5: Application - UseCase
- [ ] Create `SyncAllStockItemsUseCase`
- [ ] Create `SyncResult` VO (or reuse pattern)

### Phase 6: Presentation - Job
- [ ] Create `SyncLinnworksStockItemsJob`

### Phase 7: Scheduling & Wiring
- [ ] Register repository in `LinnworksServiceProvider`
- [ ] Add daily 5am schedule to `routes/console.php`

---

## PR Notes

<!-- Draft PR description here before creating -->

---

## Open Questions Resolved

| Question | Resolution |
|----------|------------|
| Weight unit from API | TBD - check API response |
| EP volume per item | TBD - check API response |

---

## Issues Encountered

<!-- Log issues and solutions here -->
