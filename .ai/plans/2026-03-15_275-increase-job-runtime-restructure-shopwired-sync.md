# Plan: Increase Job Runtime to 2.5hrs + Restructure ShopWired Sync Scheduling

## Context

With the recent addition of webhook functionality for all ShopWired sync entities (orders, customers, products), the aggressive polling schedule (micro every 5min, quick every hour) is now largely redundant. Webhooks provide real-time updates for create/update/delete events. This change:

1. Increases max job runtime to 2.5 hours (9000s) to accommodate large full syncs
2. Restructures full syncs to monthly (first Sunday), with 3+ hour gap between order and customer syncs
3. Reduces bulk polling: removes micro tier, reduces quick tier from hourly to every 6 hours
4. Adds product sync + reconciliation to the monthly schedule (previously unscheduled)

---

## 1. Timeout Configuration Changes

### `config/queue.php` — `retry_after`
- Redis connection default: `90` → `10800` (3 hours)
- **Why**: If `retry_after` < job timeout, Laravel re-queues the job thinking it failed, causing duplicate execution. Must be > longest job timeout (9000s).
- **DEPLOY STEP**: `REDIS_QUEUE_RETRY_AFTER` is set to `4500` in Railway (shared to alz-worker/alz-web). Update it to `10800` in Railway dashboard, or remove it to use the new code default.

### `config/database.php` — Redis `read_timeout`
- Add `'read_timeout' => 0` to the `default` Redis connection
- **Why**: phpredis `read_timeout` controls how long the PHP Redis client waits for a response before throwing `RedisException`. Default is 0 (unlimited), but hosting environments can override via php.ini `default_socket_timeout`. Explicit `0` ensures no socket-level timeout during long-running jobs. Note: `-1` would mean "use php.ini default" (typically 60s) — do NOT use `-1`.

### `config/horizon.php` — Supervisor timeouts
- `defaults.supervisor-low.timeout`: `4200` → `9000`
- `environments.production.supervisor-low.timeout`: `4200` → `9000`
- `environments.production.supervisor-low.maxTime`: `9000` → `10800` (3hrs, gives buffer above job timeout for worker lifecycle)
- **Why**: Horizon's supervisor `timeout` kills jobs exceeding this limit. Must match the desired max job duration.

### Job-level `$timeout` and `$uniqueFor`
| Job | `$timeout` | `$uniqueFor` |
|-----|-----------|-------------|
| `SyncShopwiredOrdersJob` | `4200` → `9000` | `4500` → `10000` |
| `SyncShopwiredCustomersJob` | `4200` → `9000` | `4500` → `10000` |
| `SyncShopwiredOrdersRangeJob` | `4200` → `9000` | N/A (not unique) |

### `.run/Queue.run.xml` — Local dev
- `--timeout=3600` → `--timeout=9000`

---

## 2. Scheduling Changes (`ShopwiredScheduleServiceProvider`)

### Full Syncs → Monthly (first Sunday of month)

**Order full sync**: First Sunday at **01:00 UK time**
```php
Schedule::job(new SyncShopwiredOrdersJob())
    ->name('sync-shopwired-orders-full')
    ->cron('0 1 * * 0')
    ->timezone('Europe/London')
    ->when(fn () => Carbon::now('Europe/London')->day <= 7)
    ->onOneServer()
    ->withoutOverlapping(160);
```

**Customer full sync**: First Sunday at **04:00 UK time** (3-hour gap after orders)
```php
Schedule::job(new SyncShopwiredCustomersJob())
    ->name('sync-shopwired-customers-full')
    ->cron('0 4 * * 0')
    ->timezone('Europe/London')
    ->when(fn () => Carbon::now('Europe/London')->day <= 7)
    ->onOneServer()
    ->withoutOverlapping(160);
```

**Product full sync**: First Sunday at **07:00 UK time** (after customers finish)
```php
Schedule::job(new SyncShopwiredProductsJob())
    ->name('sync-shopwired-products-full')
    ->cron('0 7 * * 0')
    ->timezone('Europe/London')
    ->when(fn () => Carbon::now('Europe/London')->day <= 7)
    ->onOneServer()
    ->withoutOverlapping(20);
```

**Product reconciliation**: First Sunday at **07:30 UK time** (after product sync)
```php
Schedule::job(new ReconcileShopwiredProductsJob())
    ->name('reconcile-shopwired-products')
    ->cron('30 7 * * 0')
    ->timezone('Europe/London')
    ->when(fn () => Carbon::now('Europe/London')->day <= 7)
    ->onOneServer()
    ->withoutOverlapping(10);
```

### Quick Syncs → Every 6 Hours (safety net)

**Orders quick sync**: Every 6 hours, skip during monthly sync window
```php
Schedule::job(new SyncShopwiredOrdersJob(maxPages: 5))
    ->name('sync-shopwired-orders-quick')
    ->everySixHours()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->skip($skipDuringMonthlySync);
```

**Customers quick sync**: Every 6 hours, skip during monthly sync window
```php
Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 5, maxNonTradePages: 5))
    ->name('sync-shopwired-customers-quick')
    ->everySixHours()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->skip($skipDuringMonthlySync);
```

### Micro Syncs → REMOVED
- Delete `sync-shopwired-orders-micro` schedule entry
- Delete `sync-shopwired-customers-micro` schedule entry

### Sync Window Check → Monthly
Rename `createWeeklySyncWindowCheck()` → `createMonthlySyncWindowCheck()`:
```php
private function createMonthlySyncWindowCheck(): Closure
{
    return static function (): bool {
        $ukTime = Carbon::now('Europe/London');
        return $ukTime->isSunday()
            && $ukTime->day <= 7
            && $ukTime->hour >= 1
            && $ukTime->hour < 8;
    };
}
```

### Webhook Health Check
- Keep daily at 03:00 UK unchanged (still important as primary webhook monitoring)

---

## 3. Docstring/Comment Updates

- Update class docblock on `ShopwiredScheduleServiceProvider` to reflect new 2-tier strategy (monthly full + 6-hourly quick)
- Update `SyncShopwiredOrdersJob` and `SyncShopwiredCustomersJob` docblocks to reflect monthly full sync
- Update schedule name constants: `weekly` → `full`, `hourly` → `quick`

---

## Files to Modify

| File | Changes |
|------|---------|
| `config/queue.php` | `retry_after` default 90 → 10800 |
| `config/database.php` | Add `read_timeout` to Redis default connection |
| `config/horizon.php` | supervisor-low timeout 4200 → 9000, maxTime 9000 → 10800 |
| `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php` | Major restructure (monthly full, 6-hourly quick, remove micro, add products) |
| `app/Application/Jobs/Shopwired/SyncShopwiredOrdersJob.php` | timeout 4200→9000, uniqueFor 4500→10000 |
| `app/Application/Jobs/Shopwired/SyncShopwiredCustomersJob.php` | timeout 4200→9000, uniqueFor 4500→10000 |
| `app/Application/Jobs/Shopwired/SyncShopwiredOrdersRangeJob.php` | timeout 4200→9000 |
| `.run/Queue.run.xml` | --timeout=3600 → --timeout=9000 |

---

## Verification

1. **Linting**: `make lint` — ensure all changes pass PHPStan, Pint, PHPArkitect, Deptrac
2. **Tests**: `make test` — run full test suite
3. **Manual verification**:
   - Review `php artisan schedule:list` output to confirm new schedule times
   - Verify monthly `when()` callback logic: first Sunday detection with `day <= 7`
   - Confirm no jobs have `$timeout` > `retry_after` (would cause duplicate execution)
4. **Railway deployment step**:
   - Update `REDIS_QUEUE_RETRY_AFTER` from `4500` → `10800` in Railway dashboard (alz-worker/alz-web services)
5. **Post-deploy monitoring**:
   - Watch Horizon dashboard on first Sunday of April (2026-04-05) for full sync execution
   - Verify quick syncs run every 6 hours and skip during monthly window
   - Monitor webhook health check continues daily at 03:00
