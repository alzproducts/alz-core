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

## Implementation Progress

- [ ] Database: Migration for `operations.sku_changes`
- [ ] Domain: Enums (`SkuUpdateType`, `SkuUpdateReason`)
- [ ] Domain: `SkuUpdateCommand` DTO
- [ ] Domain: Exceptions (`SkuUpdateFailedException`, `SkuGenerationFailedException`)
- [ ] Application: `InventoryUpdateClientInterface`
- [ ] Application: `SkuChangeRepositoryInterface`
- [ ] Application: `SkuUpdateResult`
- [ ] Application: `UpdateSkuUseCase`
- [ ] Infrastructure: `InventoryUpdateClient` (Linnworks)
- [ ] Infrastructure: Modify `ProductUpdateClient` (ShopWired)
- [ ] Infrastructure: `EloquentSkuChangeRepository`
- [ ] Infrastructure: `SkuChange` Eloquent model
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

## PR Notes

<!-- Draft PR description here -->

