# Implementation Log — Issue #423
## Linnworks Purchase Order Sync — Phase 1: Data Layer

**Branch:** `feature/423-linnworks-purchase-order-phase-1-data-layer`
**Status:** Ready for PR

---

## Decisions

### API Response Structure (verified via tinker)
- **CORRECTED**: Header is NESTED under `PurchaseOrderHeader` key, NOT at root level
- Existing `getPurchaseOrder()` was broken — passed full response to `PurchaseOrderHeaderResponse::from()`. Fixed to extract `$data['PurchaseOrderHeader']`
- `PurchaseOrderCoreResponse` restructured to use nested `PurchaseOrderHeaderResponse` property (matching `OrderResponse` composite pattern)

### PurchaseOrderNote — Complete Rewrite
- API returns `NoteDateTime` (not `DateTime`), `UserName` (not separate `Forename`/`Surname`)
- Docs were completely wrong — verified against real API response
- VO simplified: replaced `pkPurchaseId`, `forename`, `surname` with `userName: ?string`
- `pkPurchaseId` removed from VO — redundant, repository sets FK from context
- Migration updated: `forename`/`surname` columns replaced with `user_name`

### PurchaseOrderItemResponse field fixes
- `SKU` field: API returns all-caps `SKU`, PascalCaseMapper produced `Sku`. Added explicit `#[MapInputName('SKU')]`
- `BinRack` field: Not always present in API response. Made optional with `''` default (moved after required params)

### PurchaseOrderExtendedPropertyResponse.username
- API returns `UserName` (capital N), PascalCaseMapper produced `Username`. Added explicit `#[MapInputName('UserName')]`
- Was silently mapping to `null` before fix

### searchPurchaseOrders() — Typed Return
- Changed return type from `array{results: list<array>, totalRecords: int}` to `PaginatedListDTO<PurchaseOrderHeader>`
- Reuses existing `PurchaseOrderHeaderResponse` for parsing (search results have identical fields to header)
- Not in original plan but addresses raw-array anti-pattern

### PurchaseOrderNote.pkPurchaseOrderNoteId
- Confirmed real GUIDs (e.g., `3b2f0009-3f19-47d2-b5ec-e1e2536462c3`)

### pkDeliveryRecordId type
- Confirmed integer (e.g., `9441`) — migration correct

### TaxRate — property is `->percentage` (not `->value`)
- Fixed in `PurchaseOrderItemModel.attributesFromDomain()`
- Fixed in `PurchaseOrderAdditionalCostModel.attributesFromDomain()`
- Fixed in `EloquentPurchaseOrderSyncRepository.coreToAttributes()`

### Money — private `$amount` accessor
- `Money.$amount` is private; no raw getter
- Used `->toNet()` for `postagePaid` (created as `Money::exclusive(...)`)

### PurchaseOrderSyncRepositoryInterface
- Extends `RepositoryWriteInterface<PurchaseOrderFull>` with `save(object $entity)` pattern (matching existing repo pattern)
- Added typed `saveCore(PurchaseOrderCore $purchaseOrder): void` as second method

### PurchaseOrderExtendedProperty.rowId
- Existing VO has `?int $rowId` (nullable)
- Migration uses `row_id integer nullable unique` to match
- Orphan-delete in repository only runs when `$rowIds !== []`

### Additional costs without cost item ID
- `PurchaseOrderAdditionalCost.purchaseAdditionalCostItemId` is `?int`
- Orphan-delete guarded: only runs when `$costIds !== []`

---

## API Verification Results (2026-03-30)

All 9 `PurchaseOrderClient` endpoints tested against real Linnworks API:

| Endpoint | Status | Issues Found |
|----------|--------|-------------|
| `getPurchaseOrder()` | Fixed | Header nested under `PurchaseOrderHeader`, not root |
| `getPurchaseOrderCore()` | Fixed | `SKU` all-caps, `BinRack` sometimes absent |
| `getPurchaseOrderFull()` | Works | Notes + EPs populate correctly |
| `searchPurchaseOrders()` | Improved | Changed to typed `PaginatedListDTO<PurchaseOrderHeader>` |
| `getPurchaseOrderExtendedProperties()` | Fixed | `UserName` case mismatch |
| `getAdditionalCosts()` | Works | All 16 fields map correctly |
| `getAdditionalCostTypes()` | Works | Returns `AdditionalTypes` array |
| `getPurchaseOrderNotes()` | Fixed | Complete rewrite — API fields differ from docs |
| `getPurchaseOrdersWithStockItems()` | Works | Returns `list<string>` |

**Test POs used:**
- `af770cd5-c134-4b54-86b6-00062e018d4c` — DELIVERED, 4 items, 4 delivered records, 1 cost, 4 EPs, 0 notes
- `0fc466ff-4925-4588-a1a2-b509dadc069f` — DELIVERED, 1 item (no BinRack in response)
- `8198c177-00e2-4a4f-a9ac-b42b1642dfcb` — test note manually added, verified note mapping + DB save

---

## Files Created/Modified

### Domain VOs
- **MODIFIED:** `app/Domain/Linnworks/ValueObjects/PurchaseOrderNote.php` — replaced `pkPurchaseId`/`forename`/`surname` with `userName`
- **CREATED:** `app/Domain/Linnworks/ValueObjects/PurchaseOrderItem.php` — 25 fields
- **CREATED:** `app/Domain/Linnworks/ValueObjects/PurchaseOrderDeliveredRecord.php` — 6 fields
- **CREATED:** `app/Domain/Linnworks/ValueObjects/PurchaseOrderCore.php` — single-call composite
- **CREATED:** `app/Domain/Linnworks/ValueObjects/PurchaseOrderFull.php` — three-call composite

### Response DTOs
- **MODIFIED:** `app/Infrastructure/Linnworks/Responses/PurchaseOrder/PurchaseOrderNoteResponse.php` — complete rewrite (NoteDateTime, UserName)
- **MODIFIED:** `app/Infrastructure/Linnworks/Responses/PurchaseOrder/PurchaseOrderExtendedPropertyResponse.php` — added `#[MapInputName('UserName')]`
- **CREATED:** `app/Infrastructure/Linnworks/Responses/PurchaseOrder/PurchaseOrderItemResponse.php` — `#[MapInputName('SKU')]`, optional `binRack`
- **CREATED:** `app/Infrastructure/Linnworks/Responses/PurchaseOrder/PurchaseOrderDeliveredRecordResponse.php`
- **CREATED:** `app/Infrastructure/Linnworks/Responses/PurchaseOrder/PurchaseOrderCoreResponse.php` — nested `PurchaseOrderHeaderResponse` + child arrays

### Client
- **MODIFIED:** `app/Application/Contracts/Linnworks/PurchaseOrderClientInterface.php` — added `getPurchaseOrderCore()`, `getPurchaseOrderFull()`, typed `searchPurchaseOrders()` return, `getPurchaseOrdersWithStockItems()` params/return to `Guid`
- **MODIFIED:** `app/Infrastructure/Linnworks/Clients/PurchaseOrderClient.php` — fixed `getPurchaseOrder()` header extraction, implemented core/full, typed search, typed stock items
- **MODIFIED:** `app/Infrastructure/Linnworks/Clients/PurchaseOrderUpdateClient.php` — added `@unverified` tags to class and all methods (ported from legacy, untested against real API)

### Dashboard Queries
- **CREATED:** `app/Infrastructure/Linnworks/Queries/PurchaseOrderIdsByDateQuery.php`
- **CREATED:** `app/Infrastructure/Linnworks/Queries/OpenPendingPurchaseOrderIdsQuery.php`
- **CREATED:** `app/Application/Contracts/Linnworks/PurchaseDashboardsClientInterface.php`
- **CREATED:** `app/Infrastructure/Linnworks/Clients/PurchaseDashboardsClient.php`

### Database Migrations (all ran successfully)
- `2026_03_30_100000_create_linnworks_purchase_orders_table.php`
- `2026_03_30_100001_create_linnworks_purchase_order_items_table.php`
- `2026_03_30_100002_create_linnworks_purchase_order_additional_costs_table.php`
- `2026_03_30_100003_create_linnworks_purchase_order_delivered_records_table.php`
- `2026_03_30_100004_create_linnworks_purchase_order_notes_table.php` — updated: `forename`/`surname` → `user_name`
- `2026_03_30_100005_create_linnworks_purchase_order_extended_properties_table.php`

### Eloquent Models
- **CREATED:** `app/Infrastructure/Linnworks/Models/PurchaseOrderModel.php`
- **CREATED:** `app/Infrastructure/Linnworks/Models/PurchaseOrderItemModel.php`
- **CREATED:** `app/Infrastructure/Linnworks/Models/PurchaseOrderAdditionalCostModel.php`
- **CREATED:** `app/Infrastructure/Linnworks/Models/PurchaseOrderDeliveredRecordModel.php`
- **CREATED:** `app/Infrastructure/Linnworks/Models/PurchaseOrderNoteModel.php` — updated: `forename`/`surname` → `user_name`
- **CREATED:** `app/Infrastructure/Linnworks/Models/PurchaseOrderExtendedPropertyModel.php`

### Repository
- **CREATED:** `app/Application/Contracts/Linnworks/PurchaseOrderSyncRepositoryInterface.php`
- **CREATED:** `app/Infrastructure/Linnworks/Repositories/EloquentPurchaseOrderSyncRepository.php`

### Bindings
- **MODIFIED:** `app/Providers/LinnworksServiceProvider.php` — added `PurchaseDashboardsClientInterface` + `PurchaseOrderSyncRepositoryInterface`
- **MODIFIED:** `app/Infrastructure/Linnworks/LinnworksClientFactory.php` — added `createPurchaseDashboardsClient()`

### Complexity Baseline
- **MODIFIED:** `phpstan-complexity-baseline.neon` — added baseline entries for structural `alz.excessiveMethodLength` violations

---

## Simplify Results
- Removed redundant what-comments from repository `save()` / `saveCore()`
- Added orphan-delete clarification comments for nullable-ID guards in `syncAdditionalCosts()` / `syncExtendedProperties()`
- Condensed `PurchaseOrderCoreResponse` map methods to one-liner ternaries (attempted generic `mapCollection()` but reverted due to PHPStan type safety)
- Updated complexity baseline entries to match new line counts

## Sweep Results
- All checks passed clean — no issues found
- Architecture, exception handling, domain types, naming, and layer boundaries all correct

## Lint/Test Status
- `make lint` — passes (Pint + PHPStan + PHPArkitect + Deptrac + TLint)
- `make test` — passes (2766 tests, 6232 assertions)
- All 6 migrations ran successfully against local Supabase

## Blockers / Open Questions
- [x] Pre-implementation API verification — DONE, header is nested (not root-level)
- [x] `pkDeliveryRecordId` type confirmation — DONE, confirmed integer
- [x] `pkPurchaseOrderNoteId` GUID confirmation — DONE, confirmed real GUIDs
- [x] Note API field names — DONE, completely different from docs

## PR Notes
- Phase 1 data layer + API verification fixes
- **Critical fix**: Existing `getPurchaseOrder()` was broken (header nested under `PurchaseOrderHeader` key, not root-level) — fixed
- 6 bugs found and fixed during API verification (nested header, SKU mapping, BinRack optional, UserName case, note fields, search return type)
- `searchPurchaseOrders()` upgraded from raw arrays to `PaginatedListDTO<PurchaseOrderHeader>`
- `getPurchaseOrdersWithStockItems()` upgraded from `string` params/return to `Guid`
- `PurchaseOrderUpdateClient` flagged with `@unverified` on class + all methods — ported from legacy, not yet tested against real API
- Complexity baseline entries added for structural violations (constructors, casts, attributesFromDomain, sync methods)
