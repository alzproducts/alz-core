# Purchase Order Sync — Phase 1: Discovery & Data Layer

## Context

We need comprehensive Purchase Order syncing from Linnworks into our local PostgreSQL database. This plan covers **Phase 1 only** — discovering what exists, identifying gaps, and defining the data-layer pieces needed to fetch and persist PO data. Phase 2 (jobs, use cases, orchestration) is out of scope.

---

## Dual Sync Strategy

Two sync levels with distinct domain types and client methods:

| Sync Level | API Calls | Domain VO | Use Case |
|------------|-----------|-----------|----------|
| **Core** (`PurchaseOrderCore`) | 1: `Get_PurchaseOrder` | Header + items + additional costs + delivered records + noteCount | Rapid polling of OPEN/PENDING POs |
| **Full** (`PurchaseOrderFull`) | 3: `Get_PurchaseOrder` + `Get_PurchaseOrderNote` + `Get_PurchaseOrderExtendedProperty` | Core + notes + extended properties | Historical backfill / complete sync |

**Client methods:**
- `getPurchaseOrderCore(Guid): PurchaseOrderCore` — single API call, fast
- `getPurchaseOrderFull(Guid): PurchaseOrderFull` — all 3 API calls, complete

**Repository methods:**
- `save(PurchaseOrderFull)` — upserts parent + all 5 child tables, orphan-deletes everything
- `saveCore(PurchaseOrderCore)` — upserts parent + items + costs + delivered records only, **does NOT touch notes/EPs** (preserves data from prior full syncs)

---

## Discovery: What Already Exists

### Domain VOs (4 existing)
| VO | Fields | Status |
|----|--------|--------|
| `PurchaseOrderHeader` | 22 | COMPLETE — matches API |
| `PurchaseOrderAdditionalCost` | 16 | COMPLETE |
| `PurchaseOrderNote` | 5 (pkPurchaseId, note, dateTime, forename, surname) | PARTIAL — **missing `pkPurchaseOrderNoteId`** (needed as upsert key) |
| `PurchaseOrderExtendedProperty` | 6 | COMPLETE |

### Response DTOs (4 existing)
All in `app/Infrastructure/Linnworks/Responses/PurchaseOrder/`:
- `PurchaseOrderHeaderResponse` — parses header from `Get_PurchaseOrder`
- `PurchaseOrderAdditionalCostResponse` — parses from `Get_Additional_Cost`
- `PurchaseOrderNoteResponse` — parses from `Get_PurchaseOrderNote`
- `PurchaseOrderExtendedPropertyResponse` — parses from `Get_PurchaseOrderExtendedProperty`

### Client Methods (PurchaseOrderClient)
| Method | Endpoint | Returns |
|--------|----------|---------|
| `getPurchaseOrder(Guid)` | `Get_PurchaseOrder` | `PurchaseOrderHeader` only |
| `getPurchaseOrderExtendedProperties(Guid)` | `Get_PurchaseOrderExtendedProperty` | `list<PurchaseOrderExtendedProperty>` |
| `getAdditionalCosts(Guid)` | `Get_Additional_Cost` | `list<PurchaseOrderAdditionalCost>` |
| `getPurchaseOrderNotes(Guid)` | `Get_PurchaseOrderNote` | `list<PurchaseOrderNote>` |

### Enums & Supporting
- `PurchaseOrderStatus` enum (Pending, Open, Partial, Delivered) with state machine
- `PurchaseOrderReference` VO for external invoice numbers

### Database
- **No tables, models, or repositories exist** for purchase orders
- Order sync pattern exists as reference: `linnworks.orders` + child tables, `EloquentLinnworksOrderRepository`

---

## Discovery: What's Missing

### 1. Domain VOs — 4 new + 1 fix

**FIX: `PurchaseOrderNote`** — add `pkPurchaseOrderNoteId: Guid` (API returns this, needed as DB upsert key)

**NEW: `PurchaseOrderItem`** — 25 fields from API, completely absent from codebase:
- Identifiers: `pkPurchaseItemId` (Guid), `fkStockItemId` (Guid), `stockItemIntId` (IntId)
- Quantities: `quantity`, `delivered`, `packQuantity`, `packSize` (int)
- Financial: `cost`, `tax` (float), `taxRate` (TaxRate)
- Product: `sku` (Sku), `itemTitle`, `barcodeNumber`, `supplierCode`, `supplierBarcode` (string)
- Physical: `dimHeight`, `dimWidth`, `dimDepth` (float)
- State: `isDeleted` (bool), `inventoryTrackingType`, `sortOrder` (int)
- Warehouse: `binRack` (string), `boundToOpenOrdersItems`, `quantityBoundToOpenOrdersItems` (int)
- Other: `skuGroupIds` (array, stored as JSONB)

**NEW: `PurchaseOrderDeliveredRecord`** — 6 fields, completely absent:
- `pkDeliveryRecordId` (IntId — needs verification, see Step 0), `fkPurchaseItemId` (Guid), `fkStockLocationId` (Guid)
- `unitCost` (float), `deliveredQuantity` (int), `createdDateTime` (?DateTimeImmutable)

**NEW: `PurchaseOrderCore`** — single API call composite (`app/Domain/Linnworks/ValueObjects/PurchaseOrderCore.php`):
- `header: PurchaseOrderHeader`
- `noteCount: int`
- `items: list<PurchaseOrderItem>`
- `additionalCosts: list<PurchaseOrderAdditionalCost>`
- `deliveredRecords: list<PurchaseOrderDeliveredRecord>`

**NEW: `PurchaseOrderFull`** — complete composite (`app/Domain/Linnworks/ValueObjects/PurchaseOrderFull.php`):
- `core: PurchaseOrderCore` (composition, not duplication)
- `notes: list<PurchaseOrderNote>`
- `extendedProperties: list<PurchaseOrderExtendedProperty>`

### 2. Response DTOs — 3 new (all Spatie LaravelData)

**NEW: `PurchaseOrderItemResponse`** — maps 25 API fields → `PurchaseOrderItem` VO

**NEW: `PurchaseOrderDeliveredRecordResponse`** — maps 6 API fields → `PurchaseOrderDeliveredRecord` VO

**NEW: `PurchaseOrderCoreResponse`** — composite Spatie Data class (follows `OrderResponse` pattern) for the full `Get_PurchaseOrder` response. Uses `#[DataCollectionOf]` for nested child arrays. Key insight: **the API returns items, costs, and delivered records IN the same response** that `getPurchaseOrder()` currently discards:
```json
{
  "NoteCount": 0,
  "PurchaseOrderHeader": { ... },
  "PurchaseOrderItem": [ ... ],
  "AdditionalCosts": [ ... ],
  "DeliveredRecords": [ ... ]
}
```
Implements `DomainConvertibleInterface` with `toDomain(): PurchaseOrderCore`. Contains nested `PurchaseOrderHeaderResponse` property + child collection arrays, matching the `OrderResponse` composite pattern exactly.

### 3. Client Methods — 2 new

**NEW: `getPurchaseOrderCore(Guid): PurchaseOrderCore`** on `PurchaseOrderClientInterface`
- Same endpoint as `getPurchaseOrder()` but extracts ALL data (not just header)
- Single API call → fast for rapid polling

**NEW: `getPurchaseOrderFull(Guid): PurchaseOrderFull`** on `PurchaseOrderClientInterface`
- Calls all 3 endpoints: `Get_PurchaseOrder` + `Get_PurchaseOrderNote` + `Get_PurchaseOrderExtendedProperty`
- Composes `PurchaseOrderFull` from core + notes + extended properties
- 3 API calls → used for historical/complete sync

Existing `getPurchaseOrder()` unchanged (other code depends on it).

### 4. Dashboard Queries — 2 queries + 1 facade client

**NEW: `PurchaseOrderIdsByDateQuery`** (`app/Infrastructure/Linnworks/Queries/PurchaseOrderIdsByDateQuery.php`) — historical backfill:
```sql
SELECT pkPurchaseID FROM [Purchase]
[WHERE DateOfPurchase >= '{from}'] [AND DateOfPurchase < '{to}']
[AND fkLocationId = '00000000-0000-0000-0000-000000000000']
ORDER BY DateOfPurchase ASC
```

**NEW: `OpenPendingPurchaseOrderIdsQuery`** (`app/Infrastructure/Linnworks/Queries/OpenPendingPurchaseOrderIdsQuery.php`) — rapid updates:
```sql
SELECT pkPurchaseID FROM [Purchase]
WHERE (Status = 'OPEN' OR Status = 'PENDING')
[AND fkLocationId = '00000000-0000-0000-0000-000000000000']
ORDER BY DateOfPurchase DESC
```

**NEW: `PurchaseDashboardsClient`** (`app/Infrastructure/Linnworks/Clients/PurchaseDashboardsClient.php`) — facade wrapping `DashboardsClient`:
- `getPurchaseOrderIdsByDate(?from, ?to, defaultLocationOnly): list<Guid>`
- `getOpenPendingPurchaseOrderIds(defaultLocationOnly): list<Guid>`

**NEW: `PurchaseDashboardsClientInterface`** (`app/Application/Contracts/Linnworks/PurchaseDashboardsClientInterface.php`)

### 5. Database Tables — 6 new tables

All in `linnworks` schema, following order sync pattern:

| Table | Upsert Key | Parent FK | Fields |
|-------|-----------|-----------|--------|
| `purchase_orders` | `linnworks_purchase_id` (uuid, unique) | — | 22 header + note_count + synced_at |
| `purchase_order_items` | `linnworks_purchase_item_id` (uuid, unique) | `linnworks_purchase_id` | 25 item fields |
| `purchase_order_additional_costs` | `linnworks_additional_cost_item_id` (int, unique) | `linnworks_purchase_id` | 16 cost fields |
| `purchase_order_delivered_records` | `linnworks_delivery_record_id` (int, unique) | `linnworks_purchase_id` | 6 record fields |
| `purchase_order_notes` | `linnworks_purchase_order_note_id` (uuid, unique) | `linnworks_purchase_id` | note, datetime, forename, surname |
| `purchase_order_extended_properties` | `row_id` (int, unique) | `linnworks_purchase_id` | property_name, property_value, added_datetime, username |

Notes get a **separate table** (not JSONB) because they have unique IDs, structured metadata, and come from a separate API endpoint.

### 6. Eloquent Models — 6 new

All in `app/Infrastructure/Linnworks/Models/`, following order model pattern (`HasUuids`, `$guarded = []`, static `attributesFromDomain()`):
- `PurchaseOrderModel` with HasMany to all 5 child models
- `PurchaseOrderItemModel`
- `PurchaseOrderAdditionalCostModel`
- `PurchaseOrderDeliveredRecordModel`
- `PurchaseOrderNoteModel`
- `PurchaseOrderExtendedPropertyModel`

### 7. Repository — 1 interface + 1 implementation

- **Interface**: `PurchaseOrderSyncRepositoryInterface` in `Application/Contracts/Linnworks/`
- **Implementation**: `EloquentPurchaseOrderSyncRepository` — atomic transaction wrapping upsert parent + upsert/orphan-delete for child tables

**Two methods:**
- `save(PurchaseOrderFull)` — upserts parent + all 5 child tables with orphan deletion
- `saveCore(PurchaseOrderCore)` — upserts parent + items + costs + delivered records only; **does NOT orphan-delete notes/EPs** (they weren't fetched, so absence doesn't mean deletion)

### 8. Service Provider / Factory Bindings

- Add `createPurchaseDashboardsClient()` to `LinnworksClientFactory`
- Register `PurchaseDashboardsClientInterface` + `PurchaseOrderSyncRepositoryInterface` bindings in `LinnworksServiceProvider`

---

## Resolved Questions

1. **`PurchaseOrderNote.pkPurchaseOrderNoteId`** — RESOLVED: Real GUIDs in practice. The example null GUID was just a doc placeholder. Use as upsert key (uuid column).

2. **`PurchaseOrderItem.SkuGroupIds`** — RESOLVED: Store as JSONB column for future-proofing.

3. **Naming** — RESOLVED: `PurchaseOrderCore` (1 API call) + `PurchaseOrderFull` (3 API calls). Both self-documenting.

4. **Composition** — RESOLVED: `getPurchaseOrderFull()` lives on the client (infrastructure), making all 3 API calls internally. `PurchaseOrderFull` composes `PurchaseOrderCore` (not duplicates fields).

5. **PurchaseOrderItem.sku** — RESOLVED: Keep strict `Sku` type (not nullable). If empty SKUs arrive during bulk sync, we'll handle them when encountered.

6. **PurchaseOrderItem.barcodeNumber** — RESOLVED: Use `string` (not `Gtin` VO). Linnworks returns empty strings and non-standard barcodes that would fail `Gtin` validation. Explicit deviation from domain type table.

7. **Repository dual methods** — RESOLVED: Define both `save(PurchaseOrderFull)` and `saveCore(PurchaseOrderCore)` in Phase 1. `saveCore()` must not orphan-delete notes/EPs.

8. **PurchaseOrderCoreResponse pattern** — RESOLVED: Extends Spatie `Data` with `#[DataCollectionOf]` for child arrays, matching `OrderResponse` composite pattern. Implements `DomainConvertibleInterface`.

## Pre-Implementation Verification (Step 0)

Two unknowns must be verified via real API calls before building:

**A. `Get_PurchaseOrder` response structure** — The existing `getPurchaseOrder()` passes `$response->json()` directly to `PurchaseOrderHeaderResponse::from()`, but the example JSON shows the header nested under `"PurchaseOrderHeader"`. The existing code may be wrong (copied from legacy without testing) or Linnworks docs may be wrong. **Must call the endpoint via tinker and inspect the raw JSON** to determine:
- Are header fields at root level or nested under `PurchaseOrderHeader`?
- Exact key names for child arrays (`PurchaseOrderItem` vs `Items`, etc.)

**B. `DeliveredRecord.pkDeliveryRecordId` type** — Example shows integers but needs confirmation. **Inspect the real response** to confirm int vs GUID. This determines the migration column type and upsert key type.

```bash
# Verification tinker command:
php artisan tinker --execute="
\$client = app(App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface::class);
\$response = \$client->postFormParams('/api/PurchaseOrder/Get_PurchaseOrder', ['pkPurchaseId' => 'af770cd5-c134-4b54-86b6-00062e018d4c']);
print_r(\$response->json());
"
```

---

## Post-Implementation Verification

- Run migrations against local Supabase
- Call `getPurchaseOrderCore()` via tinker against a real PO and verify domain VO population
- Call `getPurchaseOrderFull()` via tinker and verify notes + EPs are populated
- Call repository `save()` with a real `PurchaseOrderFull` to verify atomic persistence
- Run `make lint` + `make test` to verify all new code passes quality gates
