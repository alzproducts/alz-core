# Issue #596 — Migrate remaining read sites to View + Assembler pattern

**Branch:** `feature/596-migrate-read-sites-view-assembler`
**Plan:** `.ai/plans/2026-04-18_596-migrate-remaining-read-sites-view-assembler.md`
**Started:** 2026-04-18

## Objective

Migrate 9 read sites (7 mechanical + Reconcile + CheckExpired) from write-side VOs (`Product`, `Category`) to the View + Assembler pattern. Run a dead-code sweep to actually shrink the write-side surface.

## Workflow mode

Plan is structured as 5 sequential commit-groups. Per /work constraints, no git commits are made during this run — all changes land uncommitted and commit-grouping happens at PR-prep time.

## Decision log

- 2026-04-18 — Branch `feature/596-migrate-read-sites-view-assembler` created from `origin/develop`.
- 2026-04-18 — Will implement in commit-group order (scaffolding → mechanical swap → Reconcile → CheckExpired → dead-code sweep) because each group builds on the previous.

## Implementation progress

### Commit-group 1 — scaffolding additions
- [ ] `SaleSettings::fromTypedCustomFields()`
- [ ] `ProductView::isInCategory(IntId)`
- [ ] `ProductView::getCustomField(string)`
- [ ] `ProductView::hasCustomField(string)`
- [ ] `ProductView::totalStock()`
- [ ] `ProductView::allOnSaleSkus()`
- [ ] `ProductRepositoryInterface::findProductViewsOnSale()`
- [ ] `EloquentProductRepository::findProductViewsOnSale()`
- [ ] Unit tests for each

### Commit-group 2 — mechanical 7-site swap
- [ ] `UpdateShopwiredAddToSaleJob` (line 57)
- [ ] `UpdateShopwiredRemoveFromSaleJob` (line 57)
- [ ] `AddProductToSaleUseCase` (line 57)
- [ ] `RemoveProductFromSaleUseCase` (line 51)
- [ ] `UpdateProductCategoryMembershipUseCase` (line 49)
- [ ] `ProductPricingUpdatedSlackListener` (line 70)
- [ ] `SyncBestSellersCategoryUseCase` (line 64)
- [ ] IntId propagation through dispatcher interfaces

### Commit-group 3 — `ReconcileProductSaleStateUseCase`
- [ ] Resolver signature change
- [ ] Use case migration
- [ ] Test updates

### Commit-group 4 — `CheckExpiredSalesUseCase`
- [ ] Repo call swap
- [ ] Per-item read swaps
- [ ] Test updates

### Commit-group 5 — dead-code sweep
- [ ] Grep every candidate symbol
- [ ] Delete confirmed-dead symbols
- [ ] Remove test files exercising only deleted symbols
- [ ] Record deletions in PR notes section

## PR Notes

(To be filled in as work progresses.)

## Lint / Test results

(To be filled in during Steps 4–5.)
