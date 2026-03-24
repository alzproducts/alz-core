# Linnworks PurchaseOrder API - Infrastructure Handover

## 1. Feature Overview

The Linnworks `/api/PurchaseOrder` endpoint integration provides a complete HTTP client layer for managing purchase orders in the Linnworks inventory management system. It supports full CRUD operations on purchase orders including creation, search, status management, header updates, line items, extended properties, additional costs (shipping), and notes. The implementation follows a layered architecture: raw endpoint -> response parsing -> model hydration -> service wrappers.

## 2. Architecture Diagram

```
                         ┌──────────────────────────────────┐
                         │       Business Logic Layer       │
                         │  (Consumers - OUT OF SCOPE)      │
                         └──────────────┬───────────────────┘
                                        │
                    ┌───────────────────┼───────────────────────┐
                    │                   │                       │
         ┌──────────▼──────┐  ┌────────▼────────┐  ┌──────────▼──────────┐
         │  AlzPurchase    │  │ Linnworks\PO\    │  │  Linnworks\PO\      │
         │  (Service Hub)  │  │ PurchaseOrder    │  │  CreatePurchaseOrder│
         │                 │  │ (Domain Wrapper) │  │  (Domain Wrapper)   │
         └──────┬──────────┘  └────────┬────────┘  └──────────┬──────────┘
                │                      │                       │
   ┌────────────┼──────────────┐       │                       │
   │            │              │       │                       │
┌──▼────────┐ ┌▼──────────┐ ┌─▼───┐   │                       │
│AlzPurchase│ │AlzPurchase│ │Ship-│   │                       │
│Update     │ │Create     │ │ping │   │                       │
└──┬────────┘ └┬──────────┘ └─┬───┘   │                       │
   │           │              │       │                       │
   └───────────┴──────┬───────┴───────┴───────────────────────┘
                      │
              ┌───────▼───────────────────┐
              │  LinnApiClient            │
              │  ->purchaseOrder()        │
              └───────┬───────────────────┘
                      │
              ┌───────▼───────────────────┐
              │  Linn2\Endpoint\          │
              │  PurchaseOrder            │
              │  (17 API endpoints)       │
              └───────┬───────────────────┘
                      │
              ┌───────▼───────────────────┐
              │  Linn2\Http\RestClient    │
              │  (Guzzle POST requests)   │
              └───────┬───────────────────┘
                      │
              ┌───────▼───────────────────┐
              │  Response Type Classes    │
              │  StdOrNull, ArrOfStd...   │
              └───────┬───────────────────┘
                      │
              ┌───────▼───────────────────┐
              │  Model Hydration Layer    │
              │  AbstractBase->hydrate()  │
              └───────────────────────────┘
```

## 3. External Integration: Linnworks PurchaseOrder API

### Authentication
- **Method:** Application-level OAuth via `Auth/AuthorizeByApplication`
- **Auth Server:** `https://api.linnworks.net`
- **Token Storage:** Guzzle client is pre-configured with base_uri and auth headers via DI container
- **Keys:** `lw_server` (dynamic API server) and `lw_token` (session token) - stored in container config

### Transport
- **HTTP Client:** GuzzleHttp\Client
- **Method:** All requests sent as **POST** (despite method named `get()`)
- **Content-Type:** `application/x-www-form-urlencoded` (form_params)
- **Base URI:** Dynamic per-session (returned from auth endpoint)
- **Path Prefix:** `/api/PurchaseOrder/`

### Response Parsing
- All responses JSON-decoded with `JSON_FORCE_OBJECT` flag
- Parsed into `stdClass` objects (not arrays)
- Response type classes validate format before returning

## 4. API Endpoints (17 total)

### Read Operations

| Method | Linnworks Endpoint | Parameters | Response Type | Returns |
|--------|-------------------|------------|---------------|---------|
| `getPurchaseOrder` | `Get_PurchaseOrder` | `pkPurchaseId: string` | `StdOrNull` | `stdClass` (full PO with header, items, EPs) |
| `getPurchaseOrderNote` | `Get_PurchaseOrderNote` | `pkPurchaseId: string` | `ArrOfStdOrNull` | `stdClass[]` |
| `getPurchaseOrderExtendedProperty` | `Get_PurchaseOrderExtendedProperty` | `purchaseId: string` | `StdOrNull` | `stdClass[]` (via `->Items`) |
| `getAdditionalCost` | `Get_Additional_Cost` | `PurchaseId: string` | `StdOrNull` | `stdClass[]` (via `->items`) |
| `getAdditionalCostTypes` | `Get_AdditionalCostTypes` | _(none)_ | `StdOrNull` | `stdClass` |
| `getPurchaseOrdersWithStockItems` | `GetPurchaseOrdersWithStockItems` | `StockItemId: string, LocationIds: string[]` | `ArrOfStringOrEmpty` | `string[]` (PO IDs) |
| `searchPurchaseOrders` | `Search_PurchaseOrders` | `DateFrom, DateTo, Status, EntriesPerPage, PageNumber, Location[], Supplier[], ReferenceLike` | `StdOrNull` | `stdClass` with `Result[]` and `TotalNumberOfRecords` |

### Write Operations

| Method | Linnworks Endpoint | Parameters | Response Type | Returns |
|--------|-------------------|------------|---------------|---------|
| `createPurchaseOrderInitial` | `Create_PurchaseOrder_Initial` | `createParameters: JSON string` | `StrNonEmptyResponse` | `string` (new pkPurchaseId) |
| `addPurchaseOrderItem` | `Add_PurchaseOrderItem` | `addItemParameter: JSON string` | `StdOrNull` | `stdClass` |
| `addPurchaseOrderNote` | `Add_PurchaseOrderNote` | `pkPurchaseId: string, Note: string` | `StdOrNull` | `stdClass\|null` |
| `addPurchaseOrderExtendedProperty` | `Add_PurchaseOrderExtendedProperty` | `PurchaseId: string, ExtendedPropertyItems: array[]` | `StdOrNull` | `stdClass\|null` (via `->Items`) |
| `updatePurchaseOrderHeader` | `Update_PurchaseOrderHeader` | `updateParameter: JSON string` | `StdOrNull` | `stdClass\|null` |
| `updatePurchaseOrderExtendedProperty` | `Update_PurchaseOrderExtendedProperty` | `PurchaseId: string, ExtendedPropertyItems: array[]` | `NullResponse` | `null` |
| `changePurchaseOrderStatus` | `Change_PurchaseOrderStatus` | `pkPurchaseId: string, status: string` | `StdOrNull` | `stdClass\|null` |
| `modifyAdditionalCost` | `Modify_AdditionalCost` | `PurchaseId: string, itemsToAdd[], itemsToUpdate[], itemsToDelete[]` | `StdOrNull` | `stdClass` |
| `deletePurchaseOrderExtendedProperty` | `Delete_PurchaseOrderExtendedProperty` | `PurchaseId: string, RowIds: int[]` | `NullResponse` | `null` |
| `deletePurchaseOrder` | `Delete_PurchaseOrder` | `pkPurchaseId: string` | `NullResponse` | `null` |

### Parameter Encoding Patterns
Three distinct encoding patterns are used:

**Pattern 1: Simple key-value form params**
Direct key-value pairs passed as form_params. No JSON encoding.
```
form_params: { pkPurchaseId: "abc-123" }
```
Used by: `getPurchaseOrder`, `getPurchaseOrderNote`, `addPurchaseOrderNote`, `deletePurchaseOrder`, `getAdditionalCostTypes` (no params)

**Pattern 2: `createParamRequestJson()` helper**
Params JSON-encoded via the `AbstractEndpoint::createParamRequestJson()` helper under a key name (default `'request'`, or a custom key).
```php
// Default key 'request':
form_params: { request: '{"PurchaseId":"abc-123"}' }

// Custom key:
form_params: { searchParameter: '{"DateFrom":null,...}' }
form_params: { changeStatusParameter: '{"pkPurchaseId":"...","status":"OPEN"}' }
form_params: { purchaseOrder: '{"StockItemId":"...","LocationIds":[]}' }
```
Used by: `getAdditionalCost` (request), `getPurchaseOrderExtendedProperty` (request), `modifyAdditionalCost` (request), `deletePurchaseOrderExtendedProperty` (request), `addPurchaseOrderExtendedProperty` (request), `updatePurchaseOrderExtendedProperty` (request), `searchPurchaseOrders` (searchParameter), `changePurchaseOrderStatus` (changeStatusParameter), `getPurchaseOrdersWithStockItems` (purchaseOrder)

**Pattern 3: Manual `json_encode` with custom key**
Caller JSON-encodes the params directly and passes as a string value under a custom key. Bypasses the `createParamRequestJson()` helper but produces identical output format.
```php
form_params: { createParameters: '{"fkSupplierId":"...","fkLocationId":"..."}' }
form_params: { updateParameter: '{"pkPurchaseID":"...","PostagePaid":0}' }
form_params: { addItemParameter: '{"pkPurchaseId":"...","fkStockItemId":"..."}' }
```
Used by: `createPurchaseOrderInitial` (createParameters), `updatePurchaseOrderHeader` (updateParameter), `addPurchaseOrderItem` (addItemParameter)

## 5. Code Inventory

### Core API Layer

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Api/Linn2/src/Endpoint/PurchaseOrder.php` | `Linn2\Endpoint\PurchaseOrder` | Raw API endpoint methods (17 endpoints) |
| `legacy/src/Api/Linn2/src/Endpoint/AbstractEndpoint.php` | `Linn2\Endpoint\AbstractEndpoint` | Base endpoint with `createParamRequestJson()` helper |
| `legacy/src/Api/Linn2/src/LinnApiClient.php` | `Linn2\LinnApiClient` | Client factory; `->purchaseOrder()` accessor (lazy-loaded singleton) |
| `legacy/src/Api/Linn2/src/Http/RestClient.php` | `Linn2\Http\RestClient` | HTTP transport: Guzzle POST, response parsing, resource hydration |

### Response Type Classes

| File | Class | Behaviour |
|------|-------|-----------|
| `legacy/src/Api/Linn2/src/Http/Response/AbstractResponse.php` | `AbstractResponse` | Base: holds raw response, abstract `formatResponse()` |
| `legacy/src/Api/Linn2/src/Http/Response/StdOrNull.php` | `StdOrNull` | Asserts stdClass or null |
| `legacy/src/Api/Linn2/src/Http/Response/ArrOfStdOrNull.php` | `ArrOfStdOrNull` | Array of stdClass or null |
| `legacy/src/Api/Linn2/src/Http/Response/ArrOfStringOrEmpty.php` | `ArrOfStringOrEmpty` | Array of strings or empty array |
| `legacy/src/Api/Linn2/src/Http/Response/NullResponse.php` | `NullResponse` | Always returns null (fire-and-forget) |
| `legacy/src/Api/Linn2/src/Http/Response/StrNonEmptyResponse.php` | `StrNonEmptyResponse` | Asserts non-empty string |

### Model / DTO Classes

Note: Two parallel base classes exist in the codebase for model hydration:
- `Linn2\Contract\AbstractBase` - Simpler base, no ArrayAccess. Used by `AbstractPurchaseOrderHeader`.
- `Linn2\Model\AbstractBase` - Extends with ArrayAccess + `__get`/`__set` magic. Used by `ExtendedProperty`, `PurchaseOrderAdditionalCost`.

| File | Class | Base Class | Purpose |
|------|-------|------------|---------|
| `legacy/src/Api/Linn2/src/Model/PurchaseOrder/AbstractExtendedProperty.php` | `AbstractExtendedProperty` | `Linn2\Model\AbstractBase` | EP model: RowId, PurchaseID, AddedDateTime, Username, PropertyName, PropertyValue |
| `legacy/src/Api/Linn2/src/Model/PurchaseOrder/ExtendedProperty.php` | `ExtendedProperty` | (extends above) | Concrete EP with `extractUpdate()`, `extractNew()`, `update()` |
| `legacy/src/Api/Linn2/src/Model/PurchaseOrder/PurchaseOrderAdditionalCost.php` | `PurchaseOrderAdditionalCost` | `Linn2\Model\AbstractBase` | Additional cost model (16 fields including tax, allocation, shipping type) |
| `legacy/src/Linnworks/BaseExt/Original/AbstractPurchaseOrderHeader.php` | `AbstractPurchaseOrderHeader` | `Linn2\Contract\AbstractBase` | PO header model (22 fields: IDs, dates, costs, shipping, currency, status) |
| `legacy/src/Linnworks/PurchaseOrders/Asset/PurchaseOrderHeader.php` | `PurchaseOrderHeader` | (extends above) | Concrete header with business helpers (shipping VAT calc, dispatch num extraction) |
| `legacy/src/Linnworks/PurchaseOrders/Asset/NewExtendedProperty.php` | `NewExtendedProperty` | _(standalone)_ | Simple EP value object for creating new EPs (name, value, rowId) |
| `legacy/src/Linnworks/PurchaseOrders/InitialParams.php` | `InitialParams` | _(standalone)_ | Create PO params DTO: supplier, location, reference, currency, dates, tax, shipping |
| `legacy/src/Linnworks/PurchaseOrders/AddItem.php` | `AddItem` | _(standalone)_ | Add item params DTO: stockItemId, qty, cost (auto-calculated with tax), packSize |
| `legacy/src/Linnworks/Orders/Conversions/OrderItemToPoAddItem.php` | `OrderItemToPoAddItem` | _(standalone)_ | Static converter: transforms order item array into AddItem-compatible format |

### Service Wrapper Layer

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzPurchase.php` | `AlzPurchase` | Primary service hub: search, get header, get EPs, get costs, delegates to Update/Create/Shipping |
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzPurchase/AlzPurchaseUpdate.php` | `AlzPurchaseUpdate` | Update operations: status changes, header shipping, supplier ref, additional costs, EP CRUD |
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzPurchase/AlzPurchaseCreate.php` | `AlzPurchaseCreate` | Creates new PO via `createPurchaseOrderInitial` |
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzPurchase/Shipping.php` | `Shipping` | Empty shell class (extends AbstractEndpoint), accessed via `AlzPurchase::shipping()` |
| `legacy/src/Api/AlzApi/Handler/Endpoint/AlzPurchase/Shipping/BySupplier.php` | `BySupplier` | Shipping calculation data class by supplier, with dropship flag |
| `legacy/src/Api/AlzApi/Handler/Collection/Update/PurchaseOrderEps.php` | `AlzApi\...\PurchaseOrderEps` | EP create/update/delete orchestration: diffs current vs desired EPs, produces create/update/delete batches |

### Domain Wrapper Layer

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Linnworks/PurchaseOrders/PurchaseOrder.php` | `Linnworks\PurchaseOrders\PurchaseOrder` | Legacy domain wrapper: get by ID, add shipping, add EPs |
| `legacy/src/Linnworks/PurchaseOrders/CreatePurchaseOrder.php` | `CreatePurchaseOrder` | Orchestrates PO creation + add items |
| `legacy/src/Linnworks/PurchaseOrders/Update/AbstractUpdate.php` | `AbstractUpdate` | Base update class: holds pkPurchaseId + LwApi ref |
| `legacy/src/Linnworks/PurchaseOrders/Update/Status.php` | `Status` | Status change with validation (PENDING, OPEN, PARTIAL, DELIVERED) |
| `legacy/src/Linnworks/PurchaseOrders/Update/Header/AbstractHeader.php` | `AbstractHeader` | Header update: fetches current PO, hydrates header, applies changes, pushes update |
| `legacy/src/Linnworks/PurchaseOrders/Update/Header/UpdateHeaderString.php` | `UpdateHeaderString` | String field header update (strips spaces) |
| `legacy/src/Linnworks/PurchaseOrders/Update/Header/SupplierRefNum.php` | `SupplierRefNum` | Updates SupplierReferenceNumber field |
| `legacy/src/Linnworks/PurchaseOrders/Update/Header/Date/UpdQuotedDelDate.php` | `UpdQuotedDelDate` | Updates QuotedDeliveryDate field |
| `legacy/src/Linnworks/PurchaseOrders/Update/Header/Date/QuotedDelDatePicker.php` | `QuotedDelDatePicker` | Date picker variant: parses UK date format, converts to Linnworks ISO format |

### Collections (two distinct classes, same name)

| File | Class | Purpose |
|------|-------|---------|
| `legacy/src/Mvc/Collection/Linnworks/PurchaseOrderEps.php` | `Mvc\Collection\Linnworks\PurchaseOrderEps` | **Read-side:** Collection with query helpers (`isDropshipOrder()`, `getSupplierInvoiceNum()`, `getPropertyValueByName()`) |
| `legacy/src/Api/AlzApi/Handler/Collection/Update/PurchaseOrderEps.php` | `AlzApi\Handler\Collection\Update\PurchaseOrderEps` | **Write-side:** Orchestrates EP diff logic, produces create/update/delete batches for `AlzPurchaseUpdate` |

## 6. Data Structures

### PurchaseOrderHeader Fields (from API response)

| Field | Type | Description |
|-------|------|-------------|
| `pkPurchaseID` | `string` (GUID) | Primary key |
| `fkSupplierId` | `string` (GUID) | Supplier reference |
| `fkLocationId` | `string` (GUID) | Stock location (zero GUID = default/non-dropship) |
| `ExternalInvoiceNumber` | `string` | PO reference (format: `PO{10-digit-random}` or `PO{random}-{orderId}` for dropship) |
| `Status` | `string` | Enum: PENDING, OPEN, PARTIAL, DELIVERED |
| `Currency` | `string` | Default: GBP |
| `SupplierReferenceNumber` | `string` | Supplier's own reference |
| `Locked` | `bool` | Whether PO is locked |
| `LineCount` | `int` | Number of line items |
| `DeliveredLinesCount` | `int` | Number of delivered lines |
| `UnitAmountTaxIncludedType` | `int` | 0=Excludes Tax, 1=Includes Tax, 2=No Tax |
| `DateOfPurchase` | `string` | ISO datetime |
| `DateOfDelivery` | `string` | ISO datetime |
| `QuotedDeliveryDate` | `string` | ISO datetime |
| `PostagePaid` | `float` | Shipping cost |
| `TotalCost` | `float` | Total cost |
| `taxPaid` | `float` | Tax amount |
| `ShippingTaxRate` | `float` | Default 20.00 |
| `ConversionRate` | `float` | Currency conversion rate |
| `ConvertedShippingCost` | `float` | Converted shipping cost |
| `ConvertedShippingTax` | `float` | Converted shipping tax |
| `ConvertedOtherCost` | `float` | Converted other costs |
| `ConvertedOtherTax` | `float` | Converted other tax |
| `ConvertedGrandTotal` | `float` | Grand total in converted currency |

### ExtendedProperty Fields

| Field | Type | Description |
|-------|------|-------------|
| `RowId` | `int\|null` | Row identifier |
| `PurchaseID` | `string\|null` | Parent PO ID |
| `AddedDateTime` | `string\|null` | When added |
| `Username` | `string\|null` | Who added |
| `PropertyName` | `string` | Property key |
| `PropertyValue` | `string` | Property value |

**Known EP Names Used:**
- `IsDropship` - "True" if dropship order
- `ShippingCalculated` - "True" when shipping has been calculated
- `ShippingMethod` - Name of shipping method applied
- `SupplierInvoice` - Supplier's invoice number

### AdditionalCost Fields

| Field | Type | Description |
|-------|------|-------------|
| `PurchaseAdditionalCostItemId` | `int\|null` | Primary key |
| `AdditionalCostTypeId` | `int\|null` | Cost type ID (1 = Shipping) |
| `Reference` | `string\|null` | Cost reference/description |
| `SubTotalLineCost` | `float` | Net cost |
| `TaxRate` | `float` | Default 20.00 |
| `Tax` | `float` | Tax amount |
| `Currency` | `string\|null` | Default GBP |
| `ConversionRate` | `float` | Default 1.00 |
| `TotalLineCost` | `float` | Gross cost |
| `CostAllocation` | `stdClass[]\|null` | Allocation breakdown |
| `AllocationLocked` | `bool` | Whether allocation is locked |
| `AdditionalCostTypeName` | `string\|null` | e.g. "Shipping" |
| `AdditionalCostTypeIsShippingType` | `bool` | Is shipping type |
| `AdditionalCostTypeIsPartialAllocation` | `bool` | Partial allocation flag |
| `Print` | `bool` | Print on PO document |
| `AllocationMethod` | `string\|null` | Default "ByValue" |

### AddItem (Create PO Item) Fields

| Field | Type | Description |
|-------|------|-------------|
| `pkPurchaseId` | `string` | PO ID (must be PENDING) |
| `fkStockItemId` | `string` | Stock item GUID |
| `Qty` | `int` | Quantity |
| `Cost` | `float` | Line total incl tax (auto-calculated: unitCost * qty * (1 + taxRate/100)) |
| `TaxRate` | `float` | Tax rate |
| `PackQuantity` | `int\|null` | Items per pack (reference only) |
| `PackSize` | `int\|null` | Pack size (reference only) |

### InitialParams (Create PO) Fields

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `fkSupplierId` | `string` | _(required)_ | Supplier GUID |
| `fkLocationId` | `string` | _(required)_ | Location GUID |
| `ExternalInvoiceNumber` | `string` | Auto-generated `PO{random}[-{orderId}]` | PO reference |
| `Currency` | `string` | `GBP` | Currency code |
| `SupplierReferenceNumber` | `string` | `""` | Supplier ref |
| `UnitAmountTaxIncludedType` | `int\|null` | `null` | Tax inclusion type |
| `DateOfPurchase` | `DateTime` | `now()` | Purchase date |
| `QuotedDeliveryDate` | `DateTime\|null` | `null` | Expected delivery |
| `PostagePaid` | `float` | `0.00` | Shipping cost |
| `ShippingTaxRate` | `float` | `20.00` | Shipping tax rate |
| `ConversionRate` | `float` | `1.0` | Currency conversion |

## 7. Configuration

### DI Container Registration
- `LinnApiClient` registered in container, injected with pre-authenticated `RestClient`
- `RestClient` configured with Guzzle client holding `base_uri` (dynamic API server) and auth token
- `AlzPurchase` constructed with `LwApi` which provides `->getApi()->purchaseOrder()`

### Supplier-Specific Config
- **Zero-rated VAT shipping suppliers:** Hardcoded in `AlzPurchaseUpdate::SUPPLIERS_NO_SHIPPING_VAT`
  - `3d5df4e9-371f-4dff-80bd-4c34ff5d0390` (single supplier, gets null ShippingTaxRate instead of 20.00)

### Default Constants
- `DEFAULT_FULFILMENT` = `00000000-0000-0000-0000-000000000000` (non-dropship location)
- Default currency: `GBP`
- Default tax rate: `20.00`
- Default conversion rate: `1.0`
- Default allocation method: `ByValue`

## 8. Known Issues & Technical Debt

1. **Dual wrapper layers:** Both `AlzPurchase*` and `Linnworks\PurchaseOrders\*` wrap the same endpoint. The `Linnworks\PurchaseOrders\PurchaseOrder` class uses an older pattern (direct `LwApi` + `MySqlSupplierData`), while `AlzPurchase` is the newer, cleaner layer. Some callers still use the older layer.

2. **Misleading method name:** `RestClient::get()` actually sends **POST** requests. The `send()` method exclusively uses `$this->client->post()`.

3. **Inconsistent parameter encoding:** Some endpoints use simple form params, others JSON-encode into a `request` key, and others use custom key names (`createParameters`, `addItemParameter`, `searchParameter`, `changeStatusParameter`, `updateParameter`). This mapping must be preserved exactly. See Section 4 for the full per-endpoint breakdown.

4. **Response property casing inconsistency:** `getAdditionalCost()` unwraps `->items` (lowercase) at the endpoint level, while `getPurchaseOrderExtendedProperty()` and `addPurchaseOrderExtendedProperty()` unwrap `->Items` (uppercase). Other endpoints return raw responses. This inconsistency is from the Linnworks API itself.

5. **Null-access risk in response unwrapping:** Three endpoints unwrap a property from a `StdOrNull` response without null-guarding: `getAdditionalCost()` (`$get->items`), `getPurchaseOrderExtendedProperty()` (`$get->Items`), and `addPurchaseOrderExtendedProperty()` (`$add->Items`). If the API returns null, these will trigger a PHP warning/deprecation for property access on null. The `??` operator does not prevent the null object access error.

6. **Untyped responses:** Most API responses return raw `stdClass` rather than typed DTOs. Hydration into models happens at the `AlzPurchase` service layer via `RestClient::resourceFromResult()` / `collectResourceFromResult()`.

7. **Hardcoded supplier GUID:** Zero-rated VAT supplier list is hardcoded as a class constant in `AlzPurchaseUpdate`.

8. **Error handling swallows exceptions:** `RestClient::send()` catches all exceptions, logs via `Alert::dangerWithLog()`, and returns null. Specific "Order not found" messages are silently suppressed.

9. **Two parallel base classes:** `Linn2\Contract\AbstractBase` and `Linn2\Model\AbstractBase` both provide hydration but with different feature sets (ArrayAccess, magic methods). PO header uses the Contract variant; EP and AdditionalCost models use the Model variant. A migration should unify these.

10. **Two `PurchaseOrderEps` classes with same name:** `Mvc\Collection\Linnworks\PurchaseOrderEps` (read-side) and `AlzApi\Handler\Collection\Update\PurchaseOrderEps` (write-side) serve completely different roles but share a class name, which can cause confusion.

11. **No rate limiting or retry logic:** The HTTP client has no built-in rate limiting, retry, or circuit breaker patterns for the Linnworks API.

12. **Commented-out code:** Several files contain large blocks of commented-out legacy code (particularly in `PurchaseOrder.php` and `CreatePurchaseOrder.php`).

13. **Status validation only in domain wrapper:** `changePurchaseOrderStatus()` at the endpoint level accepts any string. Validation (PENDING, OPEN, PARTIAL, DELIVERED) only exists in `Linnworks\PurchaseOrders\Update\Status`, not enforced at the API client layer.
