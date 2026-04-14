# Implementation Log: #558 — Add Linnworks InventoryFieldUpdate pattern

## Issue Context
Linnworks `UpdateInventoryItemField` endpoint is already wired (transport, enum, auth), but only SKU updates are exposed. This adds a type-safe, extensible `InventoryFieldUpdate` VO pattern — mirroring `ProductFieldUpdate` for ShopWired — so callers get strict type enforcement per field and new fields can be added with minimal code.

## Implementation

### Sub-task 1: Domain Enum `InventoryUpdatableField`
- Created `app/Domain/Inventory/Enums/InventoryUpdatableField.php`
- Plain enum (no string backing — mapping lives in Infrastructure)
- 9 cases: Category, MinimumLevel, JIT, RetailPrice, PurchasePrice, BinRack, Barcode, Weight, Title

### Sub-task 2: Domain VO `InventoryFieldUpdate`
- Created `app/Domain/Inventory/ValueObjects/InventoryFieldUpdate.php`
- `final readonly` class, private constructor, 9 static factories
- Eagerly serialises domain types → string at construction (Money→net float string, Weight→kg string, Gtin→.value)

### Sub-task 3: Application Interface `InventoryFieldUpdateClientInterface`
- Created `app/Application/Contracts/Linnworks/InventoryFieldUpdateClientInterface.php`
- Single variadic method: `updateFields(Sku|Guid $identifier, InventoryFieldUpdate ...$updates): void`
- 5 standard Linnworks `@throws` declarations

### Sub-task 4: Infrastructure Client `InventoryFieldUpdateClient`
- Created `app/Infrastructure/Linnworks/Clients/InventoryFieldUpdateClient.php`
- Resolves identifier once via `InventoryClientInterface::resolveStockItemId()`
- Loops over updates calling `postFormParams` per field (Linnworks is one field per API call)
- `mapField()` exhaustive match → `LinnworksInventoryField` enum values (PHPStan enforces exhaustiveness)

### Sub-task 5: Factory + Provider registration
- Added `createInventoryFieldUpdateClient()` to `LinnworksClientFactory`
- Extracted `registerInventoryClients()` private method from `registerStockClients()` in `LinnworksServiceProvider` (PHPStan method length rule forced split when adding new singleton)
- Registered singleton in `registerInventoryClients()`
- Added `InventoryFieldUpdateClientInterface::class` to `provides()` array

### Sub-task 6: Integration test
- Created `tests/Integration/Infrastructure/Linnworks/Clients/InventoryFieldUpdateClientTest.php`
- Happy path: GUID identifier (no resolution), all 9 fields
- Happy path: SKU identifier (resolves via mock)
- Empty updates guard

## Test Results
- `make test`: 2996 passed, 0 failures. All new tests pass.

## Lint Results
- Pint: pass (no style changes needed)
- PHPStan: 1 error fixed — `registerStockClients()` exceeded 20-line method length limit after adding new singleton; extracted `registerInventoryClients()` private method
- PHPArkitect: no violations
- Deptrac: 0 violations
- TLint: LGTM

## Handoff Notes
- 7 files changed: 4 new files + 3 updated
- `InventoryFieldUpdate` VO uses eager serialization: domain types → string at construction, keeping Infrastructure trivial
- `mapField()` exhaustive match on `InventoryUpdatableField` enum enforces PHPStan exhaustiveness — adding a new enum case without a match arm will fail build
- 9 updatable fields added (Category, MinimumLevel, JIT, RetailPrice, PurchasePrice, BinRack, Barcode, Weight, Title); additional fields (Tracked, Dimensions, ReorderAmount) documented in plan as future additions
- One API call per field (Linnworks UpdateInventoryItemField is one-field-per-request)
