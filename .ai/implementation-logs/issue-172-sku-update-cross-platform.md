# Implementation Log: Issue #172 - Cross-Platform SKU Update

**Issue:** [#172](https://github.com/alzuk-alz/alz-core-two/issues/172)
**Plan:** `.ai/plans/2026-01-28_172-sku-update-cross-platform.md`
**Branch:** `feature/172-feat-cross-platform-sku-update-with-linnworks-shopwired-sync`
**Started:** 2026-01-28

## Decision Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Operations schema | New schema `operations` | Cross-platform operational data, follows existing pattern (shopwired_schema, linnworks_schema) |
| Job uniqueness | Fixed ID `update-sku` | Prevents GetNewItemNumber race conditions |
| Chunk size | 1 job per SKU | Error isolation, matches SetProductFreeDeliveryJob pattern |
| ShopWired product/variation | Use existing `getBasicProductBySku()` | Returns `Product\|ProductVariation`, Infrastructure branches internally |
| Update interface | `BasicProductUpdateClientInterface` | Universal for ANY BasicProduct attribute, not just SKU |
| Domain command | `UpdateBasicProductCommand` | All fields nullable for partial updates, validated types |
| Money VO | Requires `TaxType` enum | Self-documenting prices, `toGross()`/`toNet()` conversions |
| SKU VO | Strict validation | Max 40 chars, alphanumeric + hyphens/underscores |
| No stockItemId in command | Resolved in UseCase | Command is presentation-level, UseCase resolves Linnworks ID |
| Sku\|Guid union in interface | Flexibility for bulk ops | SKU requires API resolution; GUID can be passed directly for O(1)+O(n) bulk operations |
| Guid VO in Domain/ValueObjects | Generic, reusable | Works for Linnworks, Supabase, any UUID-based system |
| `postJson()` in transport | Raw JSON body | Linnworks UpdateInventoryItemField expects `application/json`, not form-encoded |
| Money: no `toFloat()`/`amount` | Force tax semantics | All price access must go through `toGross()`/`toNet()` - prevents tax-related bugs |
| ShopWired prices | Always `toGross()` | ShopWired expects tax-inclusive prices |
| ShopWired weight | `inKilograms()` | ShopWired expects weight in kg, not grams |

## Implementation Progress

- [x] Database: Migration for `operations.sku_changes`
- [x] Domain: Enums (`SkuUpdateType`, `SkuUpdateReason`)
- [x] Domain: `UpdateSkuCommand` DTO (with `getProvidedSku()` pattern)
- [x] Domain: Exceptions (`SkuUpdateFailedException`, `SkuGenerationFailedException`)
- [x] Domain: `Guid` VO (generic UUID validation)
- [x] Application: `InventoryUpdateClientInterface` (accepts `Sku|Guid`)
- [x] Application: `BasicProductUpdateClientInterface`
- [x] Application: `SkuChangeRepositoryInterface` (with `recordError` naming)
- [x] Application: `UpdateSkuUseCase` (orchestration + compensation)
- [x] Infrastructure: `InventoryClient::getNewItemNumber()`
- [x] Infrastructure: `LinnworksHttpTransport::postJson()` method
- [x] Infrastructure: `InventoryUpdateClient` (Linnworks, with Sku/Guid branching)
- [x] Infrastructure: `BasicProductUpdateClient` (ShopWired, prices=gross, weight=kg)
- [x] Infrastructure: `EloquentSkuChangeRepository` (uses `transact()` for writes)
- [x] Infrastructure: `SkuChangeModel` (UUID primary key, `HasUuids` trait)
- [ ] Presentation: `UpdateSkuJob`
- [ ] Presentation: `UpdateSkusCommand`
- [ ] Tests: Unit + Integration

## Session Notes

### 2026-01-28: Initial Implementation

**Patterns discovered from codebase exploration:**
- Linnworks clients use `LinnworksHttpTransport` + `LinnworksResponseParserTrait`
- ShopWired uses fetch-merge-PUT pattern for updates
- Jobs use smart retry with failure classification (permanent vs temporary)
- Commands use `DispatchesChunkedJobsTrait` for batch dispatch
- No existing `operations` schema - we'll create it

### 2026-01-29: Infrastructure Linnworks Client

**Sku|Guid union pattern implemented:**
- Added `Domain/ValueObjects/Guid` - generic UUID validation
- Updated `InventoryUpdateClientInterface` to accept `Sku|Guid`
- `InventoryUpdateClient` branches: GUID used directly, SKU resolved via `getStockItemBySku()`
- Benefit: Bulk operations can batch-resolve SKUs first, then pass GUIDs directly (50% fewer API calls)

**Transport enhancement:**
- Added `LinnworksHttpTransport::postJson()` for raw JSON body requests
- Linnworks `UpdateInventoryItemField` expects `application/json`, not form-encoded
- API field name is `ItemNumber` (Linnworks internal), not `SKU`

**BasicProductUpdateClient (ShopWired):**
- Uses `ProductRepositoryInterface::getBasicProductBySku()` to determine product vs variation
- Product: `PUT products/{id}`, Variation: `PUT products/{productId}/variations/{variationId}`
- All prices use `toGross()` (ShopWired = tax-inclusive)
- Weight uses `inKilograms()` (ShopWired unit = kg)

**Money VO hardening:**
- Removed `toFloat()` method to prevent bypassing tax semantics
- All callers must use `toGross()` or `toNet()` explicitly

### 2026-01-29: Audit Repository Implementation

**EloquentSkuChangeRepository:**
- Uses `DatabaseGateway::transact()` for all writes (not `query()`)
- `transact()` = write operations with transaction + exception translation
- `query()` = read operations only
- `SkuChangeModel` uses `HasUuids` trait for UUID primary key generation
- Binding added to `DatabaseServiceProvider` (deferred)

## PR Notes

<!-- Draft PR description here -->

