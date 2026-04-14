# Plan: Linnworks InventoryFieldUpdate Pattern

## Context

We can already call Linnworks `UpdateInventoryItemField` (the transport, endpoint, and full `LinnworksInventoryField` enum are all in place), but only SKU updates are exposed via `updateSku()`. We want a type-safe, extensible way to update arbitrary inventory fields — matching the `ProductFieldUpdate` pattern used for ShopWired.

The user specifically wants: **Category**, **MinimumLevel**, **JIT** immediately, plus recommendations for others.

## Approach

Follow the established `*FieldUpdate` pattern exactly: Domain enum + Domain VO (private ctor, static factories) + Application interface + Infrastructure client with exhaustive `mapField()`.

**Key difference from ShopWired:** Linnworks `UpdateInventoryItemField` accepts **one field per API call** (not a batch PUT). The client will accept variadic updates but loop internally, resolving the identifier once.

## Files to Create

### 1. Domain Enum — `app/Domain/Inventory/Enums/InventoryUpdatableField.php`

Plain enum (no string backing — API field name mapping lives in Infrastructure).

Only fields that are **safe and meaningful to update programmatically**. Excludes:
- `SKU` — has dedicated `updateSku()` method
- `Image` — has dedicated `addImage()` method  
- `StockLevel`, `Available`, `InOrder`, `StockValue`, `Due` — managed by Linnworks stock system
- `CreatedDate`, `ModifiedDate` — system timestamps

Cases to include now:

| Case | Factory Param Type | API Value | Why |
|------|-------------------|-----------|-----|
| `Category` | `string` | string | User requested — assign stock items to categories |
| `MinimumLevel` | `int` | string (int cast) | User requested — low stock alerts |
| `JIT` | `bool` | `'true'`/`'false'` | User requested — just-in-time indicator |
| `RetailPrice` | `Money` | string (net float) | Common — price management |
| `PurchasePrice` | `Money` | string (net float) | Common — cost tracking |
| `BinRack` | `string` | string | Common — warehouse location |
| `Barcode` | `Gtin` | string (.value) | Common — product identification |
| `Weight` | `Weight` | string (kg float) | Common — shipping calculations |
| `Title` | `string` | string | Common — product naming |

### 2. Domain VO — `app/Domain/Inventory/ValueObjects/InventoryFieldUpdate.php`

`final readonly class` with private constructor. Accepts domain types in factories, stores the **serialised string value** ready for the API (since `UpdateInventoryItemField` always takes `fieldValue` as a string).

```
private __construct(InventoryUpdatableField $field, string $value)

::category(string $categoryName): self
::minimumLevel(int $level): self                    // stores (string) $level
::jit(bool $enabled): self                          // stores 'true'/'false'
::retailPrice(Money $price): self                   // stores (string) $price->toNet()
::purchasePrice(Money $price): self                 // stores (string) $price->toNet()
::binRack(string $location): self
::barcode(Gtin $barcode): self                      // stores $barcode->value
::weight(Weight $weight): self                      // stores (string) $weight->kilograms
::title(string $title): self
```

**Design note:** The VO eagerly converts domain types to their string API representation. This keeps the Infrastructure client trivial (just passes `$update->value` through) and ensures domain type validation happens at construction time, not at send time.

### 3. Application Interface — `app/Application/Contracts/Linnworks/InventoryFieldUpdateClientInterface.php`

```php
public function updateFields(Sku|Guid $identifier, InventoryFieldUpdate ...$updates): void;
```

Single method, variadic. Declares `@throws` for the standard 5 Linnworks domain exceptions.

### 4. Infrastructure Client — `app/Infrastructure/Linnworks/Clients/InventoryFieldUpdateClient.php`

- Dependencies: `LinnworksTransportInterface`, `InventoryClientInterface` (for `resolveStockItemId`)
- Resolves identifier once, then loops over updates calling `postFormParams` per field
- `mapField()` exhaustive match → `LinnworksInventoryField` enum `->value` strings (PHPStan enforces exhaustiveness)
- Client is trivial: just passes `$update->value` (already serialised by the VO) as `fieldValue`
- Follows existing `InventoryUpdateClient` patterns exactly

### 5. Service Provider Binding — `app/Providers/LinnworksServiceProvider.php`

Register `InventoryFieldUpdateClientInterface` → `InventoryFieldUpdateClient` singleton via `LinnworksClientFactory`.

### 6. Factory Method — `app/Infrastructure/Linnworks/LinnworksClientFactory.php`

Add `createInventoryFieldUpdateClient()` — same pattern as `createInventoryUpdateClient()`.

## Recommendations: Additional Fields to Add Later

| Field | Factory Type | Rationale |
|-------|-------------|-----------|
| `Tracked` | `bool` | Toggle stock tracking on/off |
| `Dimensions` | `Dimensions` | Sets DimHeight/DimWidth/DimDepth — needs 3 API calls internally |
| `ReorderAmount` | `int` | Automated reorder quantity |
| `DefaultSupplier` | `string` | Supplier assignment |
| `VariationGroupName` | `string` | Variation group management |
| `SerialNumberScanRequired` / `BatchNumberScanRequired` | `bool` | Warehouse scanning config |

These can be added trivially later — just one enum case + one static factory + one match arm each.

## Verification

1. `make lint` — PHPStan validates match exhaustiveness, Deptrac validates layer boundaries
2. `make test` — existing tests pass (no breaking changes)
3. Integration test for new client following `InventoryUpdateClientTest` patterns (Http::fake, assert form params)

## Existing Code to Reuse

- **Pattern template:** `ProductFieldUpdate` (`app/Domain/Catalog/Product/ValueObjects/ProductFieldUpdate.php`)
- **Pattern template:** `ProductFieldUpdateClient` (`app/Infrastructure/Shopwired/Clients/ProductFieldUpdateClient.php`)
- **Enum reference:** `LinnworksInventoryField` (`app/Domain/Inventory/Enums/LinnworksInventoryField.php`) — used in `mapField()`
- **ID resolution:** `InventoryClientInterface::resolveStockItemId()` — existing Sku|Guid→Guid resolver
- **Transport:** `LinnworksTransportInterface::postFormParams()` — existing transport method
- **Test pattern:** `InventoryUpdateClientTest` (`tests/Integration/Infrastructure/Linnworks/Clients/InventoryUpdateClientTest.php`)
- **Factory:** `LinnworksClientFactory` (`app/Infrastructure/Linnworks/LinnworksClientFactory.php`)
- **Provider:** `LinnworksServiceProvider` (`app/Providers/LinnworksServiceProvider.php`)
