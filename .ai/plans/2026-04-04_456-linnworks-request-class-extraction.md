# Plan: Issue #456 — Migrate inline payload construction to Request classes

## Context

`Infrastructure/CLAUDE.md` documents a canonical Request class pattern for Linnworks client payload construction. `UpdateStockSupplierStatRequest` is the reference implementation. Six client methods still build payloads inline — this issue extracts each into a dedicated Request class.

## Approach

Create 6 new `final readonly` Request classes under `app/Infrastructure/Linnworks/Requests/`, then update 3 client files to delegate to them. Each extraction is independent — no cross-dependencies.

### Reference pattern (UpdateStockSupplierStatRequest)
- `final readonly class` + `private __construct`
- Static factory (`fromDomain`/`fromResolved`/`fromCommand`) accepts domain types, extracts scalars
- `toArray()` returns API-keyed array
- Null filtering via `array_filter` only when nullable fields exist that Linnworks would reject

---

## New Request Classes

### 1. `AddInventoryItemRequest.php`
**Factory**: `fromCommand(Guid $stockItemId, Guid $categoryId, AddInventoryItemCommand $command)`
- Tax rate mapping (`isStandard() ? -1.0 : percentage`) moves here — Linnworks-specific structural mapping
- `purchasePrice` null → `0.0`, barcode null → `''`
- **No null filtering** — all 8 fields always present

**`toArray()`** keys: `StockItemId`, `ItemNumber`, `ItemTitle`, `CategoryId`, `RetailPrice`, `PurchasePrice`, `TaxRate`, `BarcodeNumber`

### 2. `CreateStockSupplierStatRequest.php`
**Factory**: `fromResolved(Guid $stockItemId, SupplierLinkParams $params)`
- `purchasePrice` null → `0.0`, `supplierCode` null → `''`
- **No null filtering** — all 5 fields always present

**`toArray()`** keys: `StockItemId`, `SupplierID`, `PurchasePrice`, `Code`, `IsDefault`

### 3. `ExtendedPropertyRequest.php`
**Factory**: `fromWrite(ExtendedPropertyWrite $property, Guid $stockItemId, ?string $rowId = null)`
- `PropertyType` hardcoded to `'Attribute'`
- `pkRowId` conditionally included (explicit `if`, not `array_filter`)
- Preserves `ProperyName` typo (Linnworks API expects this)

**`toArray()`** keys: `fkStockItemId`, `ProperyName`, `PropertyValue`, `PropertyType`, conditional `pkRowId`

### 4. `CreatePurchaseOrderInitialRequest.php`
**Factory**: `fromCommand(CreatePurchaseOrderCommand $command, PurchaseOrderReference $reference, DateTimeImmutable $dateOfPurchase)`
- Date default resolved in **client** (not factory) to keep factory pure/deterministic
- Date formatting: `->format('Y-m-d\TH:i:s')`
- `Money::toNet()`, `TaxRate::percentage`
- **No null filtering** — preserve exact current wire format (nulls pass through `json_encode` as `null`). Null filtering would change API behavior; address separately if needed.

**`toArray()`** keys: `fkSupplierId`, `fkLocationId`, `ExternalInvoiceNumber`, `Currency`, `SupplierReferenceNumber`, `UnitAmountTaxIncludedType`, `DateOfPurchase`, `QuotedDeliveryDate`, `PostagePaid`, `ShippingTaxRate`, `ConversionRate`

### 5. `ChangePurchaseOrderStatusRequest.php`
**Factory**: `fromResolved(Guid $purchaseId, PurchaseOrderStatus $status)`
- Simplest — 2 fields, no null handling

**`toArray()`** keys: `pkPurchaseId`, `status`

### 6. `GetPurchaseOrdersWithStockItemsRequest.php`
**Factory**: `fromResolved(Guid $stockItemId, array $locationIds)` where `$locationIds` is `list<Guid>`
- `array_map` to extract Guid values moves into factory

**`toArray()`** keys: `StockItemId`, `LocationIds`

---

## Client Modifications

### `InventoryUpdateClient.php` (3 methods)

**`addInventoryItem()`** (line 73): Replace lines 78-89 with:
```php
$request = AddInventoryItemRequest::fromCommand($stockItemId, $categoryId, $command);
```
Transport: `params: ['inventoryItem' => $request->toArray()]`
UUID generation + return stays in client.

**`createSupplierStat()`** (line 108): Replace lines 112-118 with:
```php
$request = CreateStockSupplierStatRequest::fromResolved($stockItemId, $params);
```
Transport: `params: ['itemSuppliers' => [$request->toArray()]]`
Resolution call stays in client.

**`setExtendedProperties()`** (line 197) + `createExtendedProperty()` (line 269):
- Replace `self::buildExtendedPropertyPayload(...)` calls with `ExtendedPropertyRequest::fromWrite(...)->toArray()`
- **Delete** `buildExtendedPropertyPayload()` private method (lines 284-302)
- Orchestration logic (compare existing, split updates/creates) stays in client

### `PurchaseOrderUpdateClient.php` (2 methods)

**`createPurchaseOrderInitial()`** (line 60):
```php
$dateOfPurchase = $command->dateOfPurchase ?? new DateTimeImmutable();
$request = CreatePurchaseOrderInitialRequest::fromCommand($command, $reference, $dateOfPurchase);
// params: ['createParameters' => \json_encode($request->toArray(), JSON_THROW_ON_ERROR)]
```
Response parsing stays unchanged. Date default resolved in client to keep Request factory pure.

**`changePurchaseOrderStatus()`** (line 124):
```php
$request = ChangePurchaseOrderStatusRequest::fromResolved($purchaseId, $status);
// params: ['changeStatusParameter' => \json_encode($request->toArray(), JSON_THROW_ON_ERROR)]
```

### `PurchaseOrderClient.php` (1 method)

**`getPurchaseOrdersWithStockItems()`** (line 338):
```php
$request = GetPurchaseOrdersWithStockItemsRequest::fromResolved($stockItemId, $locationIds);
// params: ['purchaseOrder' => \json_encode($request->toArray(), JSON_THROW_ON_ERROR)]
```
Response parsing stays unchanged.

---

## Unit Tests

Each Request class gets a test under `tests/Unit/Infrastructure/Linnworks/Requests/`:

| Test | Key assertions |
|------|----------------|
| `AddInventoryItemRequestTest` | Tax rate -1.0 for standard, null barcode → `''`, gross for retail, net for purchase |
| `CreateStockSupplierStatRequestTest` | Null coercions (price → 0.0, code → `''`), key names |
| `ExtendedPropertyRequestTest` | `ProperyName` typo preserved, `pkRowId` present/absent |
| `CreatePurchaseOrderInitialRequestTest` | Date formatting, nullable fields preserved as-is (no filtering) |
| `ChangePurchaseOrderStatusRequestTest` | Key names, value extraction |
| `GetPurchaseOrdersWithStockItemsRequestTest` | Guid array → string array mapping |

---

## Implementation Order

1. Create all 6 Request classes (independent — can be done sequentially)
2. Update 3 client files to delegate to Request classes
3. Write unit tests for each Request class
4. `make lint` + `make test`

## Critical Files

- `app/Infrastructure/Linnworks/Requests/UpdateStockSupplierStatRequest.php` — reference pattern
- `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` — 3 methods + delete helper
- `app/Infrastructure/Linnworks/Clients/PurchaseOrderUpdateClient.php` — 2 methods
- `app/Infrastructure/Linnworks/Clients/PurchaseOrderClient.php` — 1 method
- `app/Domain/Inventory/Commands/AddInventoryItemCommand.php` — domain types for factories
- `app/Domain/Inventory/ValueObjects/SupplierLinkParams.php` — domain types for factories
- `app/Domain/Inventory/ValueObjects/ExtendedPropertyWrite.php` — domain types for factories
- `app/Application/Linnworks/UseCases/PurchaseOrder/CreatePurchaseOrderCommand.php` — command DTO

## Verification

1. `make lint` — Pint + PHPStan + PHPArkitect + Deptrac pass
2. `make test` — all existing tests pass (no behavioral changes)
3. Unit tests for all 6 Request classes pass
4. Each client method is a concise delegator (no inline payload arrays)
