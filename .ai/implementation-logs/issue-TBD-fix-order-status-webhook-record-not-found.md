# Fix: Order Status Webhook RecordNotFoundException Storm (Sentry ALZ-CORE-AA)

## Status: Complete — creating issue/branch/PR

## What Was Done

### Root Cause
`order.status_changed` webhooks arriving for orders not in `shopwired.orders` table caused
`RecordNotFoundException` → HTTP 500 → ShopWired webhook retry storm.
128 occurrences in 10 hours for order `external_id = 11715507` (confirmed missing from prod DB).

### Fix 1: UpdateOrderStatusUseCase (catch + dispatch sync)
- **File**: `app/Application/Shopwired/UseCases/Webhooks/UpdateOrderStatusUseCase.php`
- Wrapped `orderRepository->updateStatus()` in try/catch for `RecordNotFoundException`
- On catch: log warning + dispatch `SyncShopwiredOrderJob` to backfill from ShopWired API
- Extracted `shouldProcess()` private method (staleness + idempotency guards) to stay under 20-line limit
- Removed `@throws RecordNotFoundException` from `execute()` docblock (now handled internally)
- Removed stale baseline entry from `phpstan-complexity-baseline.neon`

### Fix 2: Hourly order micro-sync schedule
- **File**: `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php`
- Added `sync-shopwired-orders-hourly` (1 page, ~100 orders) running every hour
- Extracted `registerMonthlyFullOrderSync()` to keep `registerOrderSchedules()` under 20 lines
- Updated `SyncShopwiredOrdersJob` docblock to document 3-tier strategy
- 3-tier strategy: monthly full + 6-hourly quick (5 pages) + hourly micro (1 page)

## Key Decisions
- **Fix B chosen** (catch + dispatch sync) over simple skip: self-heals race conditions and pre-PR-#670 backlog
- Idempotency NOT recorded in catch path (we didn't apply the status update)
- Hourly sync skips during monthly full-sync window (first Sunday 01:00–08:00 UK)

## Files Changed
1. `app/Application/Shopwired/UseCases/Webhooks/UpdateOrderStatusUseCase.php`
2. `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php`
3. `app/Infrastructure/Jobs/Shopwired/SyncShopwiredOrdersJob.php` (docblock only)
4. `phpstan-complexity-baseline.neon` (removed stale entry)

## PR Notes
- Fixes Sentry ALZ-CORE-AA
- No behaviour changes to other webhook handlers
- No DB migration needed
