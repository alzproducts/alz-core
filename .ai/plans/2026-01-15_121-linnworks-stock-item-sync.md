# Linnworks StockItem Sync Implementation Plan

**Issue**: #121
**Date**: 2026-01-15
**Branch**: `feature/121-implement-linnworks-stock-item-sync`

---

## Overview

Implement bulk synchronization of ~10k StockItems from Linnworks to PostgreSQL, following the established ShopWired customer sync pattern. Includes syncing all Extended Properties (EPs) to a separate table.

> **📌 Implementation Note**: Reference the ShopWired customer sync implementation (`SyncShopwiredCustomersJob`, `SyncAllCustomersUseCase`, `EloquentCustomerRepository`, `CustomerModel`, etc.) at each phase. This prevents re-encountering issues we've already solved: pagination patterns, upsert strategies, exception handling, queue configuration, and Clean Architecture layering.

---

## Key Decisions (User Confirmed)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Sync Strategy | **Upsert** | Update existing by StockItemId, insert new. Preserves local modifications. |
| EP Handling | **Delete + Re-insert** | On each StockItem sync, delete all EPs for that item, insert fresh from API. |
| EP Scope | **All EPs** | Sync every Extended Property returned by API. |
| Schedule | **Daily 5am** | Match ShopWired customer sync. Stock items change frequently. |

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│ PRESENTATION                                                     │
│ SyncLinnworksStockItemsJob                                       │
│ - Queue: low (long-running bulk sync)                           │
│ - Timeout: 60 min                                                │
│ - Retries: 5 with exponential backoff                           │
└──────────────────┬──────────────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────────────────┐
│ APPLICATION                                                      │
│ SyncAllStockItemsUseCase                                         │
│ - Orchestrates fetch → buffer → persist                         │
│ - Generator-based iteration (memory efficient)                  │
│ - Returns SyncResult {fetched, saved, failed}                   │
└──────────────────┬──────────────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────────────────┐
│ INFRASTRUCTURE                                                   │
│ InventoryClient (extend existing)                               │
│ - Add iterateStockItemBatches() using GetStockItemsFull         │
│ - Pagination: entriesPerPage=200, pageNumber increments         │
│ - dataRequirements: include ExtendedProperties                  │
│                                                                  │
│ StockItemRepository (new)                                       │
│ - Upsert stock items by stock_item_id                          │
│ - Delete + re-insert EPs per item                               │
│ - Batch operations for efficiency                               │
└──────────────────┬──────────────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────────────────┐
│ DOMAIN                                                           │
│ StockItem value object (enhance existing)                       │
│ StockItemExtendedProperty value object (new)                    │
│ Dimensions value object (new) - height, width, depth            │
│ Weight value object (new) - value + WeightUnit enum (kg/gram)   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### Table: `linnworks.stock_items`

Core fields (from audit report - ESSENTIAL + IMPORTANT):
- `id` (UUID, PK) - Internal identifier
- `stock_item_id` (VARCHAR, UNIQUE) - Linnworks GUID
- `item_number` (VARCHAR, INDEX) - SKU
- `item_title` (VARCHAR)
- `purchase_price` (DECIMAL)
- `quantity`, `available`, `retail_price`, `tax_rate`, `minimum_level` (from LOW USE)
- `height`, `width`, `depth` (stored flat in DB, mapped to Dimensions VO in Domain)
- `weight` (stored flat in DB, mapped to Weight VO with WeightUnit enum)
- `created_at`, `updated_at` (Laravel timestamps)

### Table: `linnworks.stock_item_extended_properties`

- `id` (UUID, PK)
- `stock_item_id` (VARCHAR, FK → stock_items.stock_item_id, INDEX)
- `pk_row_id` (VARCHAR, NULLABLE) - Linnworks EP GUID
- `property_name` (VARCHAR, INDEX)
- `property_value` (TEXT)
- `property_type` (VARCHAR)
- `created_at`, `updated_at`

**Composite Index**: `(stock_item_id, property_name)` for efficient EP lookups.

---

## API Integration

### Endpoint: `POST /api/Stock/GetStockItemsFull`

| Parameter | Value | Notes |
|-----------|-------|-------|
| `entriesPerPage` | 200 | Reasonable batch size |
| `pageNumber` | 1, 2, 3... | 1-indexed |
| `dataRequirements` | `["ExtendedProperties"]` | Array of strings per API docs |

**Rate Limit**: 150 req/min (better than ShopWired's 60)
**Stop Condition**: Empty/smaller result set indicates last page

### Data Volume Estimates

- ~10k stock items
- 200 items/page = ~50 pages
- At 150 req/min = under 30 seconds for pagination
- With EPs + DB writes: expect 2-5 min total runtime

---

## Implementation Phases

### Phase 1: Domain Layer
- Enhance existing `StockItem` value object with additional fields (incl. `available`)
- Create `StockItemExtendedProperty` value object
- Create `Dimensions` value object (height, width, depth)
- Create `Weight` value object (value + WeightUnit enum)
- Create `WeightUnit` enum (kg, gram)
- Add any necessary domain interfaces

### Phase 2: Database Migrations
- Create `linnworks.stock_items` table
- Create `linnworks.stock_item_extended_properties` table
- Add appropriate indexes

### Phase 3: Infrastructure - Client
- Extend `InventoryClient` with `iterateStockItemBatches()` generator
- Create response DTOs for GetStockItemsFull (including EPs)
- Handle pagination and stop condition

### Phase 4: Infrastructure - Repository
- Create `EloquentStockItemRepository`
- Implement upsert for stock items
- Implement delete-all + batch-insert for EPs

### Phase 5: Application - UseCase
- Create `SyncAllStockItemsUseCase`
- Follow ShopWired pattern: generator → buffer → flush
- Progress logging every N batches

### Phase 6: Presentation - Job
- Create `SyncLinnworksStockItemsJob`
- Queue: `low`, Timeout: 3600s, Tries: 5
- Same exception handling pattern as ShopWired

### Phase 7: Scheduling
- Add to `routes/console.php` or scheduler
- Daily 5am with `onOneServer()` and `withoutOverlapping()`

---

## Potential Issues & Mitigations

| Risk | Mitigation |
|------|------------|
| EP volume (~10k × N records) | Batch inserts, manageable at this scale |
| Memory with large EP collections | Process items individually in generator, don't accumulate |
| Linnworks rate limit changes | Use exponential backoff, respect Retry-After headers |
| API contract changes | InvalidApiResponseException fails job immediately |

---

## Testing Strategy

Per `tests/TestingStrategy.md` - test what static analysis cannot catch.

### Domain Layer (90%+ coverage, 85%+ MSI)

| Class | Test Focus |
|-------|------------|
| `StockItem` VO | Validation rules, field constraints, edge cases (empty SKU, negative prices) |
| `StockItemExtendedProperty` VO | Property name/value validation, type constraints |
| `Dimensions` VO | Zero/negative dimensions handling |
| `Weight` VO | Zero/negative weight, unit conversion if needed |
| `WeightUnit` enum | Cases exist (trivial, PHPStan covers) |

**Commands**: `make test-domain-coverage`, `make mutate-domain`

### Application Layer (70%+ coverage)

| Class | Test Focus |
|-------|------------|
| `SyncAllStockItemsUseCase` | Likely pure orchestration → minimal testing unless branching logic exists. Test error handling paths if UseCase has retry/skip logic. |

**Note**: If UseCase is pure delegation (fetch → persist), skip mutation testing per strategy. Focus tests on any transformation or decision logic.

### Infrastructure Layer (Integration tests only)

| Class | Tests Needed |
|-------|--------------|
| `InventoryClient` (bulk fetch) | 2 tests: (1) Happy path with `Http::fake` returning paginated items + EPs, (2) Error path (API error → exception translation) |
| `EloquentStockItemRepository` | 2 tests: (1) Upsert creates/updates correctly, (2) EP delete+reinsert works atomically |
| `StockItemModelMapper` | Test in repository integration tests, not isolation |

### Presentation Layer (Feature/smoke tests)

| Class | Tests Needed |
|-------|--------------|
| `SyncLinnworksStockItemsJob` | 1 feature test: Job dispatches and calls UseCase. Mock UseCase to verify delegation. |

### Test Files to Create

```
tests/
├── Unit/Domain/Inventory/
│   ├── StockItemTest.php
│   ├── StockItemExtendedPropertyTest.php
│   ├── DimensionsTest.php
│   └── WeightTest.php
├── Unit/Application/Linnworks/
│   └── SyncAllStockItemsUseCaseTest.php (if non-trivial logic)
├── Integration/Infrastructure/Linnworks/
│   ├── InventoryClientBulkFetchTest.php
│   └── EloquentStockItemRepositoryTest.php
└── Feature/Jobs/
    └── SyncLinnworksStockItemsJobTest.php
```

---

## Files to Create/Modify

### New Files
- `app/Domain/Inventory/ValueObjects/StockItemExtendedProperty.php`
- `app/Domain/Inventory/ValueObjects/Dimensions.php`
- `app/Domain/Inventory/ValueObjects/Weight.php`
- `app/Domain/Inventory/Enums/WeightUnit.php`
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php`
- `app/Infrastructure/Linnworks/Models/StockItemModel.php`
- `app/Infrastructure/Linnworks/Models/StockItemExtendedPropertyModel.php`
- `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php`
- `app/Infrastructure/Linnworks/DTOs/GetStockItemsFullResponse.php`
- `app/Application/Linnworks/UseCases/SyncAllStockItemsUseCase.php`
- `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php`
- `app/Presentation/Jobs/SyncLinnworksStockItemsJob.php`
- `database/migrations/XXXX_create_linnworks_stock_items_table.php`
- `database/migrations/XXXX_create_linnworks_stock_item_extended_properties_table.php`

### Modify
- `app/Infrastructure/Linnworks/Clients/InventoryClient.php` (add bulk fetch methods)
- `app/Application/Contracts/Linnworks/InventoryClientInterface.php` (add interface methods)
- `app/Providers/LinnworksServiceProvider.php` (register repository)
- `routes/console.php` or scheduler (add daily 5am schedule)

---

## Verification Plan

1. **Unit Tests**: Domain value objects, mappers
2. **Integration Tests**: Repository upsert/EP handling
3. **Feature Tests**: Full sync job with mocked API
4. **Manual Verification**:
   - Run sync against staging Linnworks
   - Verify record counts match API
   - Spot-check EP data integrity
   - Monitor memory usage and runtime

---

## Out of Scope (Future Issues)

- Incremental sync based on modification dates
- Real-time webhooks for stock changes
- Bidirectional sync (local → Linnworks)
- Stock level calculations (Available = Quantity - InOrder)

---

## Items to Confirm During Implementation

| Item | Question | How to Confirm |
|------|----------|----------------|
| **Weight unit** | Does Linnworks return weight in kg or grams? | Check API response from `GetStockItemsFull` - inspect `Weight` field value/documentation |
| **Pagination stop** | Empty array or smaller page size indicates last page? | Test against real API or check Linnworks docs |
| **EP count per item** | Typical number of Extended Properties per stock item? | Sample API response to estimate EP table size |

---

## Sources

- [Linnworks GetStockItemsFull API](https://apidocs.linnworks.net/reference/getstockitemsfull)
- [Linnworks API Developer Resources](https://apps.linnworks.net/Api/Method/Stock-GetStockItemsFull)
