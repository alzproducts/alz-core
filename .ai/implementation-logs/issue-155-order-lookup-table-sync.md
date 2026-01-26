# Implementation Log: Issue #155 - Order Lookup Table Sync

## Overview
**Issue**: feat: Sync order enrichment data to Mixpanel lookup table
**Branch**: `feature/155-feat-sync-order-enrichment-data-to-mixpanel-lookup-table`
**Plan**: `.ai/plans/2026-01-27_155-mixpanel-order-lookup-table-sync.md`

## Decision Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Provider location | `Infrastructure/Mixpanel/LookupTables/` | Follows existing CampaignLookupTableProvider pattern |
| DI binding location | New `MixpanelServiceProvider` | Separates Mixpanel concerns from GoogleAds |
| Schedule location | `AdsScheduleServiceProvider` | Data analytics jobs grouped together |
| Bug period hashes | Use `OrderAnalyticsHashMatcher::buildFallbackSalt()` pattern | Consistent with existing deduplication logic |

## Progress Tracker

- [x] Phase 1: Configuration
- [x] Phase 2: OrderLookupTableProvider
- [x] Phase 3: SyncOrderLookupTableJob
- [x] Phase 4: DI wiring
- [x] Phase 5: Schedule job
- [x] Phase 6: Tests (unit tests for OrderLookupTableProvider)
- [x] Linting passes
- [x] All tests pass

## Implementation Notes

### Phase 1: Configuration
- Added `order_enrichment` to `config/mixpanel.php` lookup_tables array
- Added `MIXPANEL_LOOKUP_TABLE_ORDER_ENRICHMENT` to `.env.example`

### Phase 2: OrderLookupTableProvider
- SQL uses PostgreSQL window functions for efficient single-pass calculation
- Bug period (2025-09-01 to 2026-01-26) requires duplicate rows with fallback salt
- Using `DateTimeImmutable` from `order_placed_at` for consistent timestamp handling

## PR Notes

### What
Add daily job to sync order enrichment data (LTV, first order, trade status) to Mixpanel lookup table, enabling reports to access customer/order metadata for any `order_id_hashed` event property.

### Why
Mixpanel events contain only hashed order IDs. This lookup table lets analysts join event data with customer insights (trade status, LTV) without storing PII in events.

### Key Decisions
- Schedule at 01:00 daily (before order event sync at 02:00) so lookup data is fresh
- Bug period (Sept 2025 - Jan 2026) orders get duplicate rows with both hash variants
- PostgreSQL window functions for single-pass LTV/first-order calculation
- Using `DatabaseGateway` for exception translation (enables job retry logic)

### Testing
- Unit tests for `OrderLookupTableProvider` (20 tests): bug period boundaries, field formatting, hash generation
- Manual verification via tinker confirmed 5,402 rows generated with correct data
- Job test skipped (structurally identical to `SyncCampaignLookupTableJob`)

## Files Changed

### New Files
- `app/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProvider.php` - Fetches order/customer data with window functions
- `app/Presentation/Jobs/Mixpanel/SyncOrderLookupTableJob.php` - Queue job with exception handling
- `tests/Unit/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProviderTest.php` - Unit tests

### Modified Files
- `config/mixpanel.php` - Added `order_enrichment` lookup table ID
- `.env.example` - Added `MIXPANEL_LOOKUP_TABLE_ORDER_ENRICHMENT`
- `app/Providers/MixpanelServiceProvider.php` - Added MixpanelConfig singleton, contextual binding
- `app/Providers/Schedule/MixpanelScheduleServiceProvider.php` - Added daily 01:00 schedule
- `app/Infrastructure/Mixpanel/MixpanelClientFactory.php` - Made `createConfig()` public
- `CLAUDE.md` - Added DatabaseGateway rule
