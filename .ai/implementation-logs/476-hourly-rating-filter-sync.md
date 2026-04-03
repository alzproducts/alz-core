# Implementation Log: #476 Hourly Rating Filter Sync

**GitHub Issue**: #476
**Plan Document**: .ai/plans/2026-04-04_476-hourly-customer-rating-filter-sync.md
**Status**: In Progress
**Started**: 2026-04-04

## Decision Log

- **Guard test**: Direct DB query on `shopwired.filter_groups` — confirms optionNo 15 exists with title "Customer Rating"
- **Repository model**: Uses `ProductModel` (catalog) for DB connection to query the cross-schema view
- **Dispatcher**: New `CatalogSyncDispatcherInterface` in Application/Contracts/Catalog (not reusing ShopwiredSyncDispatcherInterface — different domain)
- **optionNo as opaque int**: Flows through Application layer as plain `int` per plan — Infrastructure enum resolves, Application passes through, job consumes
- **Bug fix extended**: `UpdateShopwiredRatingsJob` had two issues — wrong circuit breaker service AND missing rate limiter

## Simplify Changes

- Extracted `\count($changes)` to `$count` variable
- Added `filterValuesForDispatch()` method to DTO — encapsulates `[] → null` rule
- Eliminated duplicated CASE in SQL view with `with_desired` CTE
- Added cross-reference comment for magic `'15'` in SQL
- Added assumption comment to `parsePostgresArray()`
- Removed unnecessary `{@inheritDoc}` on dispatcher

## Progress

- [x] Guard test
- [x] Migration (SQL view)
- [x] Enum + DTO + Interfaces
- [x] Repository + Dispatcher
- [x] Use case
- [x] Per-entity job + Orchestrator job
- [x] Service provider + Schedule + bootstrap
- [x] Unit test
- [x] Bug fix (UpdateShopwiredRatingsJob: circuit breaker + rate limiter)
- [x] Tests passing (2883 pass)
- [x] Lint passing (all 5 linters clean)
- [x] Simplify (6 improvements applied)
- [x] Sweep (clean — no issues)

## PR Notes

### What
Hourly sync that maps product review star ratings to ShopWired "Customer Rating" product filters. Products with avg rating >= 4.0 get filter "4"; >= 4.5 get both "4" and "4.5"; below 4.0 get filters removed.

### Why
Shoppers cannot currently filter by star rating. Ratings are already synced to custom fields but not reflected in filters.

### Key Decisions
- All threshold/diff logic lives in SQL view (`catalog.products_with_changed_rating_filters`) — PHP is a trivial SELECT
- Independent hourly cadence (not tied to daily 2-stage pipeline)
- Per-entity jobs on bulk queue with rate limiting for API budget control
- Guard test protects hardcoded optionNo 15

### Bug Fix
`UpdateShopwiredRatingsJob` had wrong circuit breaker service (`::reviewsio()` → `::shopwired()`) and was missing rate limiter entirely (`ServiceRateLimiter::shopwiredApi()` added).

### Testing
- Unit test: 4 scenarios (empty, add, remove, mixed)
- Integration guard test: optionNo 15 existence
- All 2883 existing tests pass
