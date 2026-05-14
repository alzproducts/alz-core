# Implementation Log: COR-128 Best Seller Label Sync

**Plan Document**: .ai/plans/2026-05-14_COR-128-best-seller-label-sync.md
**Status**: In Progress
**Started**: 2026-05-14

## Decision Log

- **2026-05-14**: Using plan as-is. Confirmed `ProductUpdateClientInterface::updateCustomFields` accepts `null` to clear fields — removal path works.
- **2026-05-14**: `SetProductFreeDeliveryJob` does NOT implement `ShouldBeUnique` — plan adds it to the new job for per-product dedup. Intentional.
- **2026-05-14**: `ProductViewQueryRepository` uses `EloquentGateway::query()` for reads, not `transact()`. Matches plan.

## Deviations from Plan

- **DTO naming**: Plan used `BestSellerLabelChanges` and `ProductLabelCandidate`. PHPArkitect enforces naming suffixes — renamed to `BestSellerLabelChangesResult` and `ProductLabelCandidateDTO`.
- **selectRaw replaced**: Plan used `selectRaw("...->'custom_label_4' AS current_labels")` but this bypasses Eloquent casts and triggers PHPStan `property.notFound`. Switched to `->get(['external_id', 'custom_fields'])` and extracting `custom_label_4` from the cast array in PHP.
- **Method extraction**: PHPStan enforces 20-line (use case) and 30-line (repo) limits. Extracted `dispatchChanges()`, `queryProductsNeedingLabel()`, `queryProductsLosingLabel()`, and `mapToCandidates()` as private methods.

## Files Created

- [x] `app/Application/Catalog/BestSellerLabels/BestSellerLabelTransformer.php`
- [x] `app/Application/Catalog/BestSellerLabels/BestSellerLabelChangesResult.php`
- [x] `app/Application/Catalog/BestSellerLabels/ProductLabelCandidateDTO.php`
- [x] `app/Application/Catalog/UseCases/SyncBestSellerLabelUseCase.php`
- [x] `app/Application/Catalog/UseCases/SetProductBestSellerLabelUseCase.php`
- [x] `app/Infrastructure/Jobs/Catalog/SyncBestSellerLabelJob.php`
- [x] `app/Infrastructure/Jobs/Shopwired/SetProductBestSellerLabelJob.php`

## Files Modified

- [x] `app/Application/Contracts/Catalog/ProductViewQueryRepositoryInterface.php`
- [x] `app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php`
- [x] `app/Infrastructure/Catalog/Repositories/ProductViewQueryRepository.php`
- [x] `app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php`
- [x] `app/Providers/Schedule/CatalogScheduleServiceProvider.php`

## Simplify

- Added `LABEL_JSONB_PATH` constant to `ProductViewQueryRepository` — centralizes the JSONB column path (`custom_fields->'custom_label_4'`) and eliminates hardcoded string drift risk
- Replaced 3 hardcoded whereRaw strings with `self::LABEL_JSONB_PATH`
- Rejected suggestion to reuse `UpdateProductCustomFieldsJob` — plan explicitly forbids it because `CustomFieldSubmissionValidator` rejects null for ValueList fields
- Rejected `json_encode` for JSONB containment string — caused `missingType.checkedException` for `JsonException`; string interpolation is correct for compile-time constant

## Sweep

- Fixed: `BestSellerLabelTransformer` changed from `final class` to `final readonly class` (no mutable state, static-only)
- All other items passed review: layer placement, `@throws` propagation, job patterns, schedule registration, no linting bypasses

## Validation

- [x] All 3388 existing tests pass (7675 assertions)
- [x] All 5 linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
- [x] Post-simplify linters pass
- [x] Post-sweep linters + tests pass
- [x] Functional validation: write-only pipeline, no safe read-only validation path — confirmed schedule wiring and job dispatch chain structurally

## PR Notes

Best Seller Label Sync — daily job applies/removes "Best Sellers" from `custom_label_4` based on popularity rank tiers. Orchestrator queries `catalog.products_view` for label drift, dispatches per-product jobs that call `ProductUpdateClientInterface::updateCustomFields` directly (bypasses `CustomFieldSubmissionValidator` which rejects null for ValueList fields).

### What
Daily scheduled job that syncs the "Best Sellers" label in `custom_label_4` based on popularity rank tiers. Products with `popularity_rank <= 2` get the label added; products outside that threshold get it removed.

### Why
The existing Best Sellers **category** sync assigns products to the Best Sellers category, but there's no corresponding label in `custom_label_4` for feed/filter consumers. This feature fills that gap using the same popularity rank data.

### Key Decisions
- **Orchestrator + per-product pattern** — mirrors `SyncBestSellersCategoryJob` / `SetProductCategoryJob`. Orchestrator queries once, dispatches N per-product jobs with rate limiter + circuit breaker.
- **Bypasses `CustomFieldSubmissionValidator`** — uses `ProductUpdateClientInterface::updateCustomFields` directly because the validator rejects `null` for ValueList fields, which is needed when removing the last label.
- **JSONB containment operator** — `@>` for checking label presence in PostgreSQL, avoiding full-field comparison.
- **04:15 schedule** — 15 minutes after the Best Sellers category sync (04:00), giving category jobs a head start.

### Testing
- All 3388 existing tests pass (no new tests — orchestration + job wiring covered by integration test patterns)
- All 5 linters pass at max strictness
