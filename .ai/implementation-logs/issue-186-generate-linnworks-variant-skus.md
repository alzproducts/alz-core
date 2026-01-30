# Implementation Log: Issue #186 - Generate Linnworks Variant SKUs

## Session: 2026-01-30

### Starting Point
- Plan committed: `.ai/plans/2026-01-30_186-generate-linnworks-variant-skus.md`
- Following the suggested implementation order from the plan

### Implementation Order (from plan)
1. IntId VO + Polymorphic lookup refactor
2. LockManager — Interface + CacheLockManager + Exception + ServiceProvider binding
3. Update UpdateSkuUseCase — Add locking
4. Domain resolvers — VariationPriceResolver, VariationImageResolver
5. Linnworks read endpoint — `getStockItemFullBySku`
6. Linnworks write endpoints — One at a time with tests
7. Application UseCase — GenerateVariantSkusUseCase
8. Console command — Manual verification

---

## Decision Log

| Decision | Rationale | Date |
|----------|-----------|------|
| | | |

---

## Progress Tracking

### Phase 1: IntId VO + Polymorphic Lookup
- [ ] Create `IntId` value object
- [ ] Update `UpdateBasicProductCommand` to accept `string|IntId`
- [ ] Refactor `ProductRepositoryInterface::getBasicProductBySku()` → `getBasicProduct(string|IntId)`
- [ ] Update `EloquentProductRepository` implementation

### Phase 2: LockManager Infrastructure
- [ ] Create `LockAcquisitionException` in Domain
- [ ] Create `LockManagerInterface` in Application/Contracts
- [ ] Create `CacheLockManager` implementation in Infrastructure
- [ ] Register in ServiceProvider

### Phase 3: Update Existing UpdateSkuUseCase
- [ ] Add locking to `UpdateSkuUseCase`

### Phase 4: Domain Resolvers
- [ ] Create `VariationPriceResolver`
- [ ] Create `VariationImageResolver`
- [ ] Unit tests for resolvers

### Phase 5: Linnworks Read Endpoint
- [ ] Add `getStockItemFullBySku` to interface
- [ ] Implement in `InventoryClient`

### Phase 6: Linnworks Write Endpoints
- [ ] `addInventoryItem`
- [ ] `createSupplierStat`
- [ ] `addExtendedProperty`
- [ ] `addImage`
- [ ] `deleteInventoryItem`

### Phase 7: Application UseCase
- [ ] Create `GenerateVariantSkusCommand`
- [ ] Create `GenerateVariantSkusResult`
- [ ] Create `GenerateVariantSkusUseCase`
- [ ] Integration tests

### Phase 8: Console Command
- [ ] Create `GenerateVariantSkusCommand` (console)
- [ ] Manual verification

---

## PR Notes
_Draft PR description here before creating the PR_

