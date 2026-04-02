# Fix: UpdateStockSupplierStat PUT semantics — read-modify-write

## Context

The `UpdateStockSupplierStat` Linnworks API uses **PUT semantics** — any missing keys in the payload get cleared/invalidated. Currently `UpdateStockSupplierStatRequest` only sends `{StockItemId, SupplierID, PurchasePrice}`, wiping all other supplier stat fields (LeadTime, Code, SupplierBarcode, MinPrice, MaxPrice, etc.).

**Fix:** Fetch existing supplier stats first, merge in the new price, send back complete objects.

---

## Design Decisions

| Question | Decision | Rationale |
|----------|----------|-----------|
| `withPurchasePrice()` on domain VO? | Yes | Immutable copy-on-write for merging new price into fetched stat |
| Merge logic location? | Transformer | Pure data transform — match fetched suppliers to commands, call `withPurchasePrice()` |
| Replace or add alongside old method? | Replace `updateBulkSupplierPurchasePrice` | Old signature fundamentally incompatible (price map vs complete objects). Single call site |
| Supplier filtering location? | Transformer | Filtering by SupplierID from bulk response is pure data, not orchestration |
| New fields nullable? | Yes | VO is used from both API (fields present) and local DB model (fields absent) |
| API key for supplier name? | `Supplier` (not `SupplierName`) | Match API response shape exactly |
| Bulk stats grouping key? | `StockItemId` (GUID) | Consistent with rest of system using GUIDs |

---

## Implementation Steps

### Step 1: Update `StockItemSupplier` domain VO

**File:** `app/Domain/Inventory/ValueObjects/StockItemSupplier.php`

- Replace `string $supplierId` → `Guid $supplierId`
- Replace `?float $purchasePrice` → `?Money $purchasePrice`
- Replace `?float $minPrice` → `?Money $minPrice`
- Replace `?float $maxPrice` → `?Money $maxPrice`
- Replace `?float $averagePrice` → `?Money $averagePrice`
- Add new fields: `?Guid $stockItemId`, `?IntId $stockItemIntId`, `?int $averageLeadTime`, `?int $supplierMinOrderQty`, `?int $supplierPackSize`
- Add `withPurchasePrice(Money $price): self` — returns new instance with updated price, all other fields copied
- Class remains `final readonly class` (no property hooks needed — `withPurchasePrice()` is a regular method)
- All Money fields use `Money::exclusive()` (cost/trade prices) — preserves zero values (0 = valid price, not "unset")
- **Implementation note:** Use `Guid::equals()` (case-insensitive) when comparing supplier IDs, not `===` on values

### Step 2a: Update existing `StockItemSupplierResponse::toDomain()` only

**File:** `app/Infrastructure/Linnworks/Responses/StockItemSupplierResponse.php`

- Do NOT add new fields — this DTO stays focused on `GetStockItemsFull` Suppliers sub-array
- Update `toDomain()` only: wrap `supplierId` in `Guid`, prices in `Money::exclusive()`, pass `null` for the 5 new domain VO fields

### Step 2b: Create NEW `StockSupplierStatResponse` for bulk endpoint

**File:** `app/Infrastructure/Linnworks/Responses/StockSupplierStatResponse.php` (new)

- Spatie Data DTO with `#[MapInputName(PascalCaseMapper::class)]` + custom `#[MapInputName('SupplierID')]`
- All 15 API fields as properties (including `stockItemId`, `stockItemIntId`, `averageLeadTime`, `supplierMinOrderQty`, `supplierPackSize`)
- `toDomain(): StockItemSupplier` — wraps all fields in domain types: `Guid`, `Money::exclusive()`, `IntId`
- `Money::exclusive()` for all price fields (preserves zero values, no `nonZeroOrNull`)

### Step 3: Update `StockItemSupplierModel` Eloquent model

**File:** `app/Infrastructure/Linnworks/Models/StockItemSupplierModel.php`

- Update `toDomain()`: primitives → domain types (Guid, Money), new fields = null (not in DB)
- Update `attributesFromDomain()`: domain types → primitives (`->value`, `->toNet()`)

### Step 4: Add `getStockSupplierStatsBulk()` to `InventoryClientInterface`

**File:** `app/Application/Contracts/Linnworks/InventoryClientInterface.php`

```php
/**
 * @param list<Guid> $stockItemIds
 * @return array<string, list<StockItemSupplier>> stockItemId GUID string → supplier stats
 */
public function getStockSupplierStatsBulk(array $stockItemIds): array;
```

### Step 5: Implement on `InventoryClient`

**File:** `app/Infrastructure/Linnworks/Clients/InventoryClient.php`

- **GET** (not POST) to `/api/Inventory/GetStockSupplierStatsBulk` with `inventoryItemIds` as query param
- Use `$this->transport->get()` — per Linnworks docs, this is a GET endpoint
- Query param format: **repeated params** (`inventoryItemIds=uuid1&inventoryItemIds=uuid2`) — pass as `['inventoryItemIds' => $guidStrings]` where `$guidStrings` is `list<string>`. Guzzle/Laravel HTTP serializes arrays as repeated params by default.
- Parse flat response as `list<StockSupplierStatResponse>` via Spatie Data (the NEW response DTO from Step 2b)
- Group by `StockItemId` → `array<string, list<StockItemSupplier>>`

### Step 6: Rewrite `UpdateStockSupplierStatRequest`

**File:** `app/Infrastructure/Linnworks/Requests/UpdateStockSupplierStatRequest.php`

- Replace `fromResolved()` with `fromDomain(StockItemSupplier $supplier): self`
- Private constructor takes all 15 scalar fields
- `toArray()` emits all 15 PascalCase API keys
- `buildBulkPayload(list<StockItemSupplier>): list<array>` — iterates, calls `fromDomain()->toArray()`
- Extract scalars: `Guid->value`, `Money->toNet()`, `IntId->value`

### Step 7: Replace method on `InventoryUpdateClientInterface`

**File:** `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php`

- Remove `updateBulkSupplierPurchasePrice(Guid $supplierGuid, array $stockItemPrices): void`
- Add `updateStockSupplierStats(array $supplierStats): void` accepting `list<StockItemSupplier>`
- Copy `@throws` declarations from existing method

### Step 8: Update `InventoryUpdateClient` implementation

**File:** `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php`

- Remove old `updateBulkSupplierPurchasePrice()`
- Add `updateStockSupplierStats()` — one-liner: build payload via request class, POST to same endpoint

### Step 9: Update `CostPriceBySupplierTransformer`

**File:** `app/Application/Linnworks/UpdateCostPriceBySupplier/CostPriceBySupplierTransformer.php`

- Add `extractStockItemGuids(array $commands, array $skuToGuid): list<Guid>` — collect resolved stockItemId GUIDs
- Add `mergeSupplierPrices(array $commands, array $skuToGuid, Guid $supplierGuid, array $statsByStockItem): array` — returns `array{list<StockItemSupplier>, list<FailedCostPriceUpdateResult>}`
  - For each resolved command: find matching supplier entry by SupplierID, call `withPurchasePrice()`, collect
  - Commands where supplier stat not found → failure
- Remove `buildPriceMap()` (no longer needed)

### Step 10: Update `UpdateCostPriceBySupplierUseCase`

**File:** `app/Application/Linnworks/UpdateCostPriceBySupplier/UpdateCostPriceBySupplierUseCase.php`

Updated flow in `performBulkUpdate()`:
1. Resolve SKUs → stockItemIds (existing)
2. Partition resolved vs unresolved (existing)
3. Resolve supplier name → GUID (existing)
4. **NEW: Fetch existing stats** via `inventoryClient->getStockSupplierStatsBulk(stockItemGuids)`
5. **NEW: Merge prices** via `Transformer::mergeSupplierPrices()` — returns updated VOs + failures
6. **CHANGED: Send complete objects** via `inventoryUpdateClient->updateStockSupplierStats($mergedStats)`

**API call count:** 3 → 4 (added `GetStockSupplierStatsBulk`)

- Inject `InventoryClientInterface` is already injected (used for `resolveStockItemIds`)
- Remove `InventoryUpdateClientInterface::updateBulkSupplierPurchasePrice` usage, replace with `updateStockSupplierStats`

### Step 11: Update tests

- **StockItemSupplier constructor changes** ripple to all test files creating this VO
- **New tests:** `withPurchasePrice()`, `mergeSupplierPrices()`, updated UseCase flow with mock for `getStockSupplierStatsBulk`
- **Updated tests:** `UpdateStockSupplierStatRequest` (now `fromDomain()` with 15 fields), UseCase tests (new mock expectations)

---

## Verification

1. `make lint` — PHPStan/Pint/Arkitect/Deptrac pass
2. `make test` — all existing + new tests pass
3. Manual: API call to bulk cost price update endpoint, verify in Linnworks that non-price fields (LeadTime, Code, etc.) are preserved

---

## Critical Files

| File | Action |
|------|--------|
| `app/Domain/Inventory/ValueObjects/StockItemSupplier.php` | Modify — add types, fields, `withPurchasePrice()` |
| `app/Infrastructure/Linnworks/Responses/StockItemSupplierResponse.php` | Modify — update `toDomain()` only (new VO constructor) |
| `app/Infrastructure/Linnworks/Responses/StockSupplierStatResponse.php` | **New** — full 15-field Spatie Data DTO for bulk stats endpoint |
| `app/Infrastructure/Linnworks/Models/StockItemSupplierModel.php` | Modify — update mapping |
| `app/Application/Contracts/Linnworks/InventoryClientInterface.php` | Modify — add `getStockSupplierStatsBulk()` |
| `app/Infrastructure/Linnworks/Clients/InventoryClient.php` | Modify — implement `getStockSupplierStatsBulk()` |
| `app/Infrastructure/Linnworks/Requests/UpdateStockSupplierStatRequest.php` | Rewrite — `fromDomain()` with all 15 fields |
| `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php` | Modify — replace method |
| `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` | Modify — replace method |
| `app/Application/Linnworks/UpdateCostPriceBySupplier/CostPriceBySupplierTransformer.php` | Modify — add merge, remove old |
| `app/Application/Linnworks/UpdateCostPriceBySupplier/UpdateCostPriceBySupplierUseCase.php` | Modify — insert fetch+merge steps |
| `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php` | Affected — calls `StockItemSupplierModel::toDomain()` (covered by Step 3) |
| `tests/Unit/Domain/Inventory/ValueObjects/StockItemSupplierTest.php` | Update — constructor args change to domain types, fixture UUIDs |
| `tests/Unit/Domain/Inventory/ValueObjects/StockItemFullTest.php` | Update — `createSupplier()` fixture uses domain types |
| `tests/Unit/Application/Inventory/Services/StockItemParamsBuilderServiceTest.php` | Update — inline `new StockItemSupplier(...)` uses domain types |
| `tests/Unit/Application/Inventory/UseCases/GenerateVariantSkusUseCaseTest.php` | Update — inline `new StockItemSupplier(...)` uses domain types |
