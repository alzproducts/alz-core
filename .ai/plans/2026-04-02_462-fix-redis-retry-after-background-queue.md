# Fix: Sentry ALZ-CORE-7B — Redis retry_after gap for ultra-long jobs

## Context

`SyncHistoricalLinnworksOrdersJob` (timeout=28800s/8h) triggers a `UniqueConstraintViolationException` on the `failed_jobs` table. Root cause: `redis-long.retry_after` (10800s/3h) is lower than the production supervisor timeout (29100s), so Redis re-releases the job while still running. Combined with `retryUntil()` overriding `$tries=1`, the job was re-released ~8 times over 24 hours. Multiple workers then race to record the failure, causing a UUID collision.

`SyncAllPurchaseOrdersJob` (timeout=21600s/6h) has the same gap but hasn't triggered yet.

**Fix approach**: Create a dedicated `background` queue tier with its own Redis connection and Horizon supervisor, properly sized for 12-hour ultra-long jobs. This preserves the 3h `retry_after` for the ~25 shorter `low`-queue jobs.

**Timeout chain** (must hold: `retry_after > supervisor timeout > job timeout`):
- `background`: retry_after (43800) > supervisor timeout (43500) > longest job (43200)
- `low`: retry_after (10800) > supervisor timeout (9300) > longest job (9000) — restored to correct values

## Changes

### 1. New queue connection: `redis-xl`

**File**: `config/queue.php`

Add after `redis-long`:
```php
// Ultra-long-running queue connection for background jobs (12h+)
// retry_after must exceed supervisor-background timeout (43500s)
'redis-xl' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 43800, // ~12.2h — exceeds supervisor timeout (43500s)
    'block_for' => 5,
    'after_commit' => true,
],
```

### 2. New QueueName enum case

**File**: `app/Infrastructure/Jobs/Enums/QueueName.php`

Add `Background = 'background'` case.

### 3. Horizon supervisor config

**File**: `config/horizon.php`

**waits** — add: `'redis-xl:background' => 300`

**defaults** — add `supervisor-background`:
```php
'supervisor-background' => [
    'connection' => 'redis-xl',
    'queue' => ['background'],
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'maxProcesses' => 1,
    'maxTime' => 50400,   // 14h — worker lifecycle buffer above 12h job timeout
    'maxJobs' => 50,
    'memory' => 512,
    'tries' => 1,         // Safety fallback — background jobs run once; each is expensive
    'timeout' => 43500,   // Must exceed longest job timeout (43200s)
    'nice' => 0,
],
```

**production** — add override:
```php
'supervisor-background' => [
    'minProcesses' => 1,
    'maxProcesses' => 2,
    'timeout' => 43500,
    'maxTime' => 50400,
    'nice' => 10,
],
```

**local** — add override:
```php
'supervisor-background' => [
    'maxProcesses' => 1,
],
```

### 4. Right-size supervisor-low (production)

With the 2 ultra-long jobs removed, the longest remaining `low`-queue job is 9000s (`SyncShopwiredOrdersJob`/`SyncShopwiredCustomersJob`).

**File**: `config/horizon.php` — production `supervisor-low`:

```php
'supervisor-low' => [
    'minProcesses' => 2,
    'maxProcesses' => 6,
    'tries' => 3,
    'timeout' => 9300,    // Must exceed longest low-queue job timeout (9000s)
    'maxTime' => 10800,   // 3h — worker lifecycle buffer
    'nice' => 10,
],
```

### 5. Update SyncHistoricalLinnworksOrdersJob

**File**: `app/Infrastructure/Jobs/Linnworks/SyncHistoricalLinnworksOrdersJob.php`

- Change `$this->onQueue(QueueName::Low->value)` → `$this->onQueue(QueueName::Background->value)`
- Increase `$timeout` from 28800 → 43200 (12h)
- Increase `$uniqueFor` from 36000 → 50400 (14h) — prevents overlap
- Remove `retryUntil()` method — contradicts `$tries=1`, was the amplifier that kept retrying for 24h
- Remove `$backoff` property — meaningless with `$tries=1` (no retries to back off from)
- Remove unused `DateTimeImmutable` import
- Update doc comment to reflect new 12h timeout

### 6. Update SyncAllPurchaseOrdersJob

**File**: `app/Infrastructure/Jobs/Linnworks/SyncAllPurchaseOrdersJob.php`

- Change `$this->onQueue(QueueName::Low->value)` → `$this->onQueue(QueueName::Background->value)`
- Keep `$timeout = 21600` — this job completes successfully within 6h, no change needed
- Keep `$uniqueFor`, `retryUntil()`, `$backoff` — all consistent with `$tries=3` + `$maxExceptions=2`

### 7. Update Jobs CLAUDE.md

**File**: `app/Infrastructure/Jobs/CLAUDE.md`

Add `background` tier to Queue Priority Tiers table:
```
| `background` | 43200s | Ultra-long-running jobs (historical backfills, full PO syncs) |
```

## Verification

1. `make lint` — PHPStan, Pint, PHPArkitect, Deptrac all pass
2. `make test` — all tests pass (no behavioral changes, only config/routing)
3. Manual review: confirm the timeout chain is correct for both tiers:
   - `low`: retry_after (10800) > supervisor timeout (9300) > longest job (9000) ✅
   - `background`: retry_after (43800) > supervisor timeout (43500) > longest job (43200) ✅
