# Implementation Log: #456 — Audit Linnworks clients - migrate inline payload construction to Request classes

## Issue Context

Six Linnworks client methods build API payloads inline as arrays, violating the Request class pattern established by `UpdateStockSupplierStatRequest`. This issue extracts each into a dedicated `final readonly` Request class with `private __construct`, static factory, and `toArray()`.

## Implementation

### Sub-task 1: Create 6 Request classes in `app/Infrastructure/Linnworks/Requests/`

- `AddInventoryItemRequest` — replaces inline array in `InventoryUpdateClient::addInventoryItem()`
- `CreateStockSupplierStatRequest` — replaces inline array in `InventoryUpdateClient::createSupplierStat()`
- `ExtendedPropertyRequest` — replaces `buildExtendedPropertyPayload()` private helper
- `CreatePurchaseOrderInitialRequest` — replaces 11-field inline array in `PurchaseOrderUpdateClient::createPurchaseOrderInitial()`
- `ChangePurchaseOrderStatusRequest` — replaces inline array in `PurchaseOrderUpdateClient::changePurchaseOrderStatus()`
- `GetPurchaseOrdersWithStockItemsRequest` — replaces inline array in `PurchaseOrderClient::getPurchaseOrdersWithStockItems()`

### Sub-task 2: Update 3 client files to delegate to Request classes

- `InventoryUpdateClient`: 3 methods updated, `buildExtendedPropertyPayload()` deleted
  - `addInventoryItem()` — delegates to `AddInventoryItemRequest::fromCommand()`
  - `createSupplierStat()` — delegates to `CreateStockSupplierStatRequest::fromResolved()`
  - `setExtendedProperties()` + `createExtendedProperty()` — delegate to `ExtendedPropertyRequest::fromWrite()`
- `PurchaseOrderUpdateClient`: 2 methods updated
  - `createPurchaseOrderInitial()` — date default resolved in client, delegates to `CreatePurchaseOrderInitialRequest::fromCommand()`
  - `changePurchaseOrderStatus()` — delegates to `ChangePurchaseOrderStatusRequest::fromResolved()`
- `PurchaseOrderClient`: 1 method updated
  - `getPurchaseOrdersWithStockItems()` — delegates to `GetPurchaseOrdersWithStockItemsRequest::fromResolved()`

## Test Results

- `make test-quick` (Domain unit tests): 1435 passed, 0 failures

## Lint Results

All linters pass after one round of fixes:
- **Pint**: pass (no style issues)
- **PHPStan**: Initially 9 errors (unmatched complexity baseline entries). Fixed by:
  - Removing 2 stale entries for `InventoryUpdateClient` (class now under 250 lines, `addInventoryItem()` now under 20 lines)
  - Removing 1 stale entry for `PurchaseOrderClient` (`getPurchaseOrdersWithStockItems()` now under 20 lines)
  - Updating class length in `PurchaseOrderClient` baseline: 323 → 319 lines
  - Updating class length in `PurchaseOrderUpdateClient` baseline: 264 → 254 lines
  - Updating method length in `PurchaseOrderUpdateClient` baseline: `createPurchaseOrderInitial()` 30 → 21 lines
- **PHPArkitect**: No violations
- **Deptrac**: No violations
- **TLint**: LGTM

## Handoff Notes

- Unit tests for the 6 Request classes were not created per the work-fast constraint. The issue success criteria includes unit tests — these should be written before the PR is created.
- The complexity baseline was updated to reflect that refactoring naturally reduced line counts across 3 client files.
- `ExtendedPropertyRequest.toArray()` uses an explicit `if` for `pkRowId` (not `array_filter`) per the plan — this preserves the exact conditional include behavior and avoids accidentally filtering falsy values.
