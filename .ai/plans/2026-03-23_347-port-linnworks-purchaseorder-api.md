# Port: Linnworks PurchaseOrder API

**Source report:** `.ai/reports/legacy/20260323_linnworks-purchaseorder-api-handover.md`
**Date:** 2026-03-23
**Scope:** Clean Architecture implementation of Linnworks PurchaseOrder API client layer + write use cases. Read use cases and local persistence deferred to follow-up.

## Business Requirements

1. **Create purchase order** — Initialize a new PO with supplier, location, currency, dates, tax, and shipping; add line items and optional extended properties in a single composite operation; auto-generate PO reference
2. **Add line items to existing PO** — Add stock items with quantity, cost, tax rate, and pack size to a PENDING purchase order
3. **Change purchase order status** — Transition between PENDING → OPEN → PARTIAL → DELIVERED with domain-level transition validation
4. **Update purchase order header** — Modify supplier reference number, quoted delivery date, and shipping cost on an existing PO
5. **Manage extended properties** — Diff current vs desired EPs and produce create/update/delete batches (supports IsDropship, ShippingCalculated, ShippingMethod, SupplierInvoice)
6. **Manage additional costs** — Add, update, and delete shipping/cost line items on a PO in a single API call
7. **Add purchase order note** — Attach a note to an existing PO
8. **Delete purchase order** — Remove a PO from Linnworks
9. **PO reference generation** — Auto-generate references in `PO{10-digit-random}` or `PO{random}-{orderId}` format
10. **Search purchase orders** — Query by date range, status, supplier, location, reference (client endpoint only — use case deferred to sync plan)
11. **Get PO details / notes / EPs / costs** — Retrieve full PO data (client endpoints only — read use cases deferred to sync plan)

## Current Infrastructure

### Available
- `LinnworksHttpTransport` — Session auth, 401 retry, exception translation (`app/Infrastructure/Linnworks/LinnworksHttpTransport.php`)
- `LinnworksResponseParserTrait` — Consistent DTO parsing (`app/Infrastructure/Linnworks/Support/LinnworksResponseParserTrait.php`)
- `LinnworksClientFactory` — Lazy singleton pattern for endpoint clients (`app/Infrastructure/Linnworks/LinnworksClientFactory.php`)
- `LinnworksConfig` — OAuth credentials, timeout, logging (`config/linnworks.php`)
- Exception hierarchy — `TransientApiFailure` / `PermanentApiFailure` with 8 concrete exceptions (`app/Domain/Exceptions/Api/`)
- `LoggingLinnworksTransport` — HTTP request/response logging decorator

### Needs Extending
- `LinnworksClientFactory` — Add `purchaseOrder()` method to expose new client
- `LinnworksServiceProvider` — Bind `PurchaseOrderClientInterface` → `PurchaseOrderClient`

### Needs Building
- **Domain**: `PurchaseOrderStatus` enum, `PurchaseOrderReference` VO, `PurchaseOrderHeader` VO, `PurchaseOrderExtendedProperty` VO, `PurchaseOrderAdditionalCost` VO, `PurchaseOrderNote` VO
- **Application**: `PurchaseOrderClientInterface`, 8 write use cases, `ExtendedPropertyDiffService`
- **Infrastructure**: `PurchaseOrderClient`, 7+ Spatie LaravelData response DTOs

## Feature Specifications

### 1. PurchaseOrderClient (Infrastructure — all 17 endpoints)

**Requirement:** Wrap all Linnworks `/api/PurchaseOrder/*` endpoints with typed methods, response DTOs, and domain value object mapping.

**Architecture:** Single `PurchaseOrderClient` implementing `PurchaseOrderClientInterface`. Injected with `LinnworksTransportInterface`. Exposed via `LinnworksClientFactory::purchaseOrder()`.

**Integration:** 17 Linnworks API endpoints (7 read, 10 write). Three parameter encoding patterns must be preserved exactly — see source report §4 "Parameter Encoding Patterns" for per-endpoint mapping.

**Data flow:**
- Write methods accept domain VOs/primitives → serialize to Linnworks API format → POST via transport → parse response DTO → map to domain VO
- Read methods call API → parse response DTO → map to domain VO

**Error handling:** Transport handles HTTP → exception translation. Client handles response parsing failures (`InvalidApiResponseException`). Known quirk: response property casing inconsistency (`->items` vs `->Items`) must be handled per-endpoint.

**Endpoints:**

| Method | Linnworks Endpoint | Transport Method | Param Encoding |
|--------|-------------------|------------------|----------------|
| `getPurchaseOrder(string $id)` | `Get_PurchaseOrder` | `postFormParams` | Simple key-value |
| `searchPurchaseOrders(SearchParams)` | `Search_PurchaseOrders` | `post` | JSON in `searchParameter` key |
| `createPurchaseOrderInitial(InitialParams)` | `Create_PurchaseOrder_Initial` | `postFormParams` | JSON in `createParameters` key |
| `addPurchaseOrderItem(AddItemParams)` | `Add_PurchaseOrderItem` | `postFormParams` | JSON in `addItemParameter` key |
| `changePurchaseOrderStatus(string, Status)` | `Change_PurchaseOrderStatus` | `post` | JSON in `changeStatusParameter` key |
| `updatePurchaseOrderHeader(HeaderUpdate)` | `Update_PurchaseOrderHeader` | `postFormParams` | JSON in `updateParameter` key |
| `getPurchaseOrderExtendedProperties(string)` | `Get_PurchaseOrderExtendedProperty` | `post` | JSON in `request` key |
| `addPurchaseOrderExtendedProperties(string, array)` | `Add_PurchaseOrderExtendedProperty` | `post` | JSON in `request` key |
| `updatePurchaseOrderExtendedProperties(string, array)` | `Update_PurchaseOrderExtendedProperty` | `post` | JSON in `request` key |
| `deletePurchaseOrderExtendedProperties(string, array)` | `Delete_PurchaseOrderExtendedProperty` | `post` | JSON in `request` key |
| `getAdditionalCosts(string)` | `Get_Additional_Cost` | `post` | JSON in `request` key |
| `getAdditionalCostTypes()` | `Get_AdditionalCostTypes` | `postFormParams` | No params |
| `modifyAdditionalCosts(string, ModifyCosts)` | `Modify_AdditionalCost` | `post` | JSON in `request` key |
| `getPurchaseOrderNotes(string)` | `Get_PurchaseOrderNote` | `postFormParams` | Simple key-value |
| `addPurchaseOrderNote(string, string)` | `Add_PurchaseOrderNote` | `postFormParams` | Simple key-value |
| `deletePurchaseOrder(string)` | `Delete_PurchaseOrder` | `postFormParams` | Simple key-value |
| `getPurchaseOrdersWithStockItems(string, array)` | `GetPurchaseOrdersWithStockItems` | `post` | JSON in `purchaseOrder` key |

### 2. CreatePurchaseOrderUseCase

**Requirement:** Create a complete purchase order in a single operation: initialize PO → add all line items → optionally add EPs.

**Architecture:** Composite Application use case. Accepts a command object with supplier, location, items, and optional EPs. Orchestrates multiple client calls.

**Data flow:**
1. Generate `PurchaseOrderReference` (domain VO)
2. Call `createPurchaseOrderInitial()` → receive new PO ID
3. For each line item: call `addPurchaseOrderItem()` with PO ID
4. If EPs provided: call `addPurchaseOrderExtendedProperties()`
5. Return PO ID on success

**Error handling:** If any step after create-initial fails → call `deletePurchaseOrder()` to clean up → rethrow original exception. Consumer sees either full success or full failure.

### 3. AddPurchaseOrderItemsUseCase

**Requirement:** Add one or more stock items to an existing PENDING purchase order.

**Architecture:** Application use case. Simple iteration over items calling client.

**Data flow:** Accept PO ID + list of item commands → for each: call `addPurchaseOrderItem()` → return added items.

**Error handling:** Standard exception propagation. Partial failure leaves items that were already added (no rollback for individual items on existing PO).

### 4. ChangePurchaseOrderStatusUseCase

**Requirement:** Transition a PO to a new status with domain-level validation of allowed transitions.

**Architecture:** Application use case. Uses `PurchaseOrderStatus` enum for transition validation.

**Data flow:**
1. Validate transition with `PurchaseOrderStatus::canTransitionTo()`
2. If invalid → throw domain exception
3. Call `changePurchaseOrderStatus()` on client

**Error handling:** Invalid transitions throw a domain exception before any API call. API failures propagate normally.

### 5. UpdatePurchaseOrderHeaderUseCase

**Requirement:** Update one or more header fields (supplier reference, quoted delivery date, shipping cost) on an existing PO.

**Architecture:** Application use case. Fetches current PO header, applies changes, pushes full header update.

**Data flow:**
1. Call `getPurchaseOrder()` to fetch current state
2. Apply field changes to header
3. Call `updatePurchaseOrderHeader()` with full header

**Error handling:** Standard. If PO not found → `ResourceNotFoundException`.

### 6. UpdatePurchaseOrderExtendedPropertiesUseCase

**Requirement:** Accept desired EP state, diff against current, and apply creates/updates/deletes.

**Architecture:** Application use case + `ExtendedPropertyDiffService` (dedicated Application service).

**Data flow:**
1. Call `getPurchaseOrderExtendedProperties()` for current state
2. Pass current + desired to `ExtendedPropertyDiffService::diff()`
3. Service returns changeset: `{toCreate: [], toUpdate: [], toDelete: []}`
4. Call add/update/delete client methods as needed

**Error handling:** Standard exception propagation. Diff service is pure logic (no external calls, no exceptions).

### 7. ModifyPurchaseOrderAdditionalCostsUseCase

**Requirement:** Add, update, and/or delete cost line items on a PO.

**Architecture:** Application use case. Maps domain cost objects to API format.

**Data flow:** Accept PO ID + items to add/update/delete → call `modifyAdditionalCosts()` → return updated costs.

**Error handling:** Standard.

### 8. AddPurchaseOrderNoteUseCase

**Requirement:** Attach a text note to a purchase order.

**Architecture:** Application use case. Thin wrapper around client call.

**Data flow:** Accept PO ID + note text → call `addPurchaseOrderNote()`.

**Error handling:** Standard.

### 9. DeletePurchaseOrderUseCase

**Requirement:** Remove a purchase order from Linnworks.

**Architecture:** Application use case. Thin wrapper around client call.

**Data flow:** Accept PO ID → call `deletePurchaseOrder()`.

**Error handling:** Standard. If PO not found → `ResourceNotFoundException`.

## Domain Types

### PurchaseOrderStatus (Enum)

```php
enum PurchaseOrderStatus: string
{
    case Pending = 'PENDING';
    case Open = 'OPEN';
    case Partial = 'PARTIAL';
    case Delivered = 'DELIVERED';

    /** @return list<self> */
    public function allowedTransitions(): array;
    public function canTransitionTo(self $target): bool;
}
```

Allowed transitions:
- PENDING → OPEN
- OPEN → PARTIAL, DELIVERED
- PARTIAL → DELIVERED
- DELIVERED → (none)

### PurchaseOrderReference (Value Object)

```php
final readonly class PurchaseOrderReference
{
    private function __construct(public string $value);
    public static function generate(): self;           // PO{10-digit-random}
    public static function forDropship(string $orderId): self;  // PO{random}-{orderId}
    public static function fromString(string $value): self;     // Parse existing
}
```

### PurchaseOrderHeader (Value Object)

Readonly VO with 22 fields matching the Linnworks API response. See source report §6 "PurchaseOrderHeader Fields".

### PurchaseOrderExtendedProperty (Value Object)

Readonly VO: `RowId`, `PurchaseID`, `AddedDateTime`, `Username`, `PropertyName`, `PropertyValue`.

### PurchaseOrderAdditionalCost (Value Object)

Readonly VO with 16 fields. See source report §6 "AdditionalCost Fields".

### PurchaseOrderNote (Value Object)

Readonly VO for note data returned by the API.

## Decisions Log

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Single `PurchaseOrderClient` for all 17 endpoints | Matches Linnworks API grouping and existing client patterns (OrderClient, InventoryClient) |
| 2 | API-only — no local persistence | Sync will be planned separately; read use cases deferred to that plan |
| 3 | Write use cases only for now | Read paths change fundamentally with sync; write paths stay the same |
| 4 | YAGNI on sync-readiness | Write use cases call client only; repository injection added when sync lands |
| 5 | Composite `CreatePurchaseOrderUseCase` | Consumers shouldn't need to know about multi-step API; matches legacy AlzPurchaseCreate pattern |
| 6 | Delete-and-rethrow on partial create failure | Consumer sees either full success or full failure; no partial POs left behind |
| 7 | EP diff as dedicated Application service | Deserves its own class for testability; not complex enough for domain, not simple enough to inline |
| 8 | Domain enum with transition validation for PO status | Fail fast before API call; transitions are a business rule, not an API concern |
| 9 | Domain value object for PO reference | Encapsulates format rules and generation; used by CreatePurchaseOrderUseCase |
| 10 | No domain events yet | YAGNI — add when concrete consumers exist (notifications, audit, etc.) |

## Proposed Implementation

### File Structure

```
app/Domain/Linnworks/
├── Enums/
│   └── PurchaseOrderStatus.php
└── ValueObjects/
    ├── PurchaseOrderReference.php
    ├── PurchaseOrderHeader.php
    ├── PurchaseOrderExtendedProperty.php
    ├── PurchaseOrderAdditionalCost.php
    └── PurchaseOrderNote.php

app/Application/
├── Contracts/Linnworks/
│   └── PurchaseOrderClientInterface.php
└── Linnworks/
    ├── UseCases/
    │   ├── CreatePurchaseOrderUseCase.php
    │   ├── AddPurchaseOrderItemsUseCase.php
    │   ├── ChangePurchaseOrderStatusUseCase.php
    │   ├── UpdatePurchaseOrderHeaderUseCase.php
    │   ├── UpdatePurchaseOrderExtendedPropertiesUseCase.php
    │   ├── ModifyPurchaseOrderAdditionalCostsUseCase.php
    │   ├── AddPurchaseOrderNoteUseCase.php
    │   └── DeletePurchaseOrderUseCase.php
    └── Services/
        └── ExtendedPropertyDiffService.php

app/Infrastructure/Linnworks/
├── Clients/
│   └── PurchaseOrderClient.php
└── Responses/PurchaseOrder/
    ├── PurchaseOrderResponse.php
    ├── PurchaseOrderHeaderResponse.php
    ├── PurchaseOrderItemResponse.php
    ├── ExtendedPropertyResponse.php
    ├── AdditionalCostResponse.php
    ├── AdditionalCostTypeResponse.php
    ├── PurchaseOrderNoteResponse.php
    └── SearchResultResponse.php
```

### Key Method Signatures

```php
// Application contract
interface PurchaseOrderClientInterface
{
    public function getPurchaseOrder(string $purchaseId): PurchaseOrderHeader;
    public function searchPurchaseOrders(/* search params */): SearchResult;
    public function createPurchaseOrderInitial(/* initial params */): string; // returns pkPurchaseId
    public function addPurchaseOrderItem(/* item params */): void;
    public function changePurchaseOrderStatus(string $purchaseId, PurchaseOrderStatus $status): void;
    public function updatePurchaseOrderHeader(/* header update params */): void;
    // ... remaining 11 endpoints
}

// Composite create
final readonly class CreatePurchaseOrderUseCase
{
    public function __construct(
        private PurchaseOrderClientInterface $client,
    ) {}

    public function execute(CreatePurchaseOrderCommand $command): string; // returns PO ID
}

// EP diff service
final readonly class ExtendedPropertyDiffService
{
    /** @return ExtendedPropertyChangeset */
    public static function diff(
        array $current,  // list<PurchaseOrderExtendedProperty>
        array $desired,  // list<DesiredExtendedProperty>
    ): ExtendedPropertyChangeset;
}
```

### Deferred (Follow-up Plan)

- Read use cases (SearchPurchaseOrdersUseCase, GetPurchaseOrderUseCase, etc.)
- Local database persistence (migrations, models, repositories)
- PO sync job (similar to SyncLinnworksOrdersJob)
- Domain events for PO lifecycle
- Supplier-specific shipping logic
- Find POs by stock item
- Order→PO item conversion
