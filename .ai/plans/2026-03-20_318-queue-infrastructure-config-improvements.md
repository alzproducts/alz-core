# Plan: Laravel Jobs Guide — Infrastructure & Config Improvements

## Context

The guide at `.ai/docs/guides/guide-to-laravel-jobs-2026.md` contains recommendations spanning job class code AND surrounding infrastructure (config, scheduling, monitoring, deployment). This plan extracts **only the non-job-class improvements** — configs, scheduling, observability, Redis, Horizon tuning, and deployment — and evaluates each for suitability in this project.

This is a learning/optimisation phase: even small-scale benefits warrant implementation.

---

## Complete Task Inventory

### Group A: Queue Configuration (`config/queue.php`)

| # | Task | Current State | Guide Recommendation | Impact |
|---|------|---------------|---------------------|--------|
| A1 | Enable `after_commit` globally | `false` on both redis + database connections | Set `after_commit: true` to prevent race conditions where workers process jobs before the dispatching transaction commits | **HIGH** — prevents subtle data-not-found bugs |
| A2 | Set `block_for` on Redis connection | `null` (non-blocking pop; Horizon controls sleep interval between checks — not a tight loop) | Set to `3`-`5` seconds — uses Redis `BRPOP` for efficient blocking at the Redis level instead of application-level sleep+pop | **LOW** — minor efficiency improvement |
| A3 | Split Redis queue connections by timeout tier | Redis: `10800s` (3hrs) retry_after for all queues | Add `redis-long` connection (retry_after=10800s) for `low` queue, tighten default `redis` to retry_after=120s for `high`/`default`. Requires updating supervisor-low to use `redis-long` connection | **MEDIUM** — faster re-release on worker death for high/default queues |

### Group B: Redis Configuration (`config/database.php`)

| # | Task | Current State | Guide Recommendation | Impact |
|---|------|---------------|---------------------|--------|
| B1 | Dedicated Redis DB for queue | Queue uses `default` connection (DB 0), cache on DB 1 | Add `queue` connection on DB 2 so queue data is fully isolated. Prevents any `default` DB operations from affecting queue | **MEDIUM** — isolation safety net |
| B2 | Redis `maxmemory-policy` = `noeviction` | Unknown — depends on Railway Redis config | Queue DB must use `noeviction` so jobs are never evicted under memory pressure | **HIGH** — but DevOps/Railway config, not code |

### Group C: Horizon Configuration (`config/horizon.php`)

| # | Task | Current State | Guide Recommendation | Impact |
|---|------|---------------|---------------------|--------|
| C1 | Schedule `horizon:snapshot` | Not scheduled | `everyFiveMinutes()` — required for Horizon metrics graphs to populate | **HIGH** — currently metrics graphs are empty |
| C2 | Add wait thresholds for all queues | Only `redis:default` at 60s | Add `redis:high` (30s) and `redis:low` (120s) | **LOW** — better alerting granularity |
| C3 | Route wait-time notifications | `LongWaitDetected` event fires but no notification routing in `HorizonServiceProvider::boot()` | Route to Slack/email/log | **MEDIUM** — depends on Slack webhook availability |
| C4 | Review auto-scaling strategy | Already set to `time` ✅ | No change needed — `time` is correct for varying job durations | **N/A** — already correct |
| C5 | Set local dev `maxJobs`/`maxTime` defaults | `maxJobs: 0, maxTime: 0` in defaults (workers never restart) | Set `maxJobs: 500, maxTime: 3600` in defaults so local dev workers restart periodically, preventing memory leak accumulation | **LOW** — local dev quality-of-life |
| C6 | Set `nice: 10` on supervisor-low | `nice: 0` on both supervisors | Lower CPU priority for bulk work. Note: only affects scheduling within the worker Railway service (web is a separate service). Marginal benefit since Horizon auto-balancing already prioritizes, but valid defense-in-depth | **LOW** — marginal within-worker-service priority |

### Group D: Scheduling

| # | Task | Current State | Guide Recommendation | Impact |
|---|------|---------------|---------------------|--------|
| D1 | Schedule `queue:prune-failed` | Not scheduled — failed jobs accumulate forever | `daily` with `--hours=168` (7 days) | **HIGH** — prevents DB table bloat |
| D2 | Schedule `horizon:snapshot` | Same as C1 | `everyFiveMinutes()` | Same as C1 |

### Group E: Monitoring & Observability

| # | Task | Current State | Guide Recommendation | Impact |
|---|------|---------------|---------------------|--------|
| E1 | Context facade for correlation IDs | Only used for RLS (`rls_user_id`) | Add `trace_id` (UUID) and `user_id` in a NEW `SetRequestContextMiddleware` (runs before auth, separate from RLS middleware). Context auto-propagates to queued jobs in Laravel 11+ via dehydrate/hydrate | **HIGH** — transforms debugging experience |
| E2 | Register `Queue::before` hook (start logging only) | None registered | Centralized job start logging: log job name, queue, attempts on every job start. `Queue::failing` deferred to future job-refactoring commit | **MEDIUM** — centralized observability |
| ~~E3~~ | ~~Explicit Sentry `Queue::failing` listener~~ | ~~Deferred~~ | Removed — failure handling changes belong with job class refactoring | **DEFERRED** |
| E4 | Queue health check endpoint | Only `/up` (Laravel built-in) | Endpoint returning queue depth + health status for monitoring/alerting | **LOW** — useful for ops dashboards |

### Group F: Deployment

| # | Task | Current State | Guide Recommendation | Impact |
|---|------|---------------|---------------------|--------|
| F1 | Verify `horizon:terminate` in deploy | Railway manages worker service separately | Workers run stale code if not restarted after deploy. Railway redeploys restart all services, so this should be automatic | **VERIFY ONLY** — likely already handled |

### Group G: Optional / Advanced

| # | Task | Current State | Guide Recommendation | Impact |
|---|------|---------------|---------------------|--------|
| G1 | Failover queue driver | Not configured | `'connections' => ['redis', 'database']` — falls back to DB if Redis dies | **LOW** — adds resilience but complexity |
| G2 | Prometheus metrics | Not installed | `spatie/laravel-prometheus` for queue depth/throughput | **LOW** — Sentry already provides performance monitoring |
| G3 | Queue pause/resume | Not used | Laravel 12 feature — programmatic pause/resume | **AWARENESS** — no config change needed |

---

## Implementation Order

Work through sequentially, evaluating and implementing each:

### Phase 1: Quick Config Wins
1. **A1** — `after_commit: true` in `config/queue.php` (both connections)
2. **A2** — `block_for: 5` on redis connection in `config/queue.php`
3. **B1** — Add `queue` Redis connection (DB 2) in `config/database.php`, update `config/queue.php` redis connection to `'connection' => 'queue'`. Note: Horizon's `use` key stays as `'default'` — it controls Horizon's own metadata storage, not queue data

### Phase 2: Scheduling
4. **D1** — Schedule `queue:prune-failed --hours=168` daily in a new or existing schedule provider
5. **C1/D2** — Schedule `horizon:snapshot` every 5 minutes in the same provider

### Phase 3: Horizon Tuning
6. **C2** — Add wait thresholds for `redis:high` and `redis:low`
7. **C3** — Route `LongWaitDetected` notification to Slack
8. **C5** — Set local dev `maxJobs: 500, maxTime: 3600` in defaults
9. **C6** — Set `nice: 10` on supervisor-low (production only)

### Phase 4: Observability
10. **E1** — Create NEW `SetRequestContextMiddleware` for correlation IDs (separate from RLS middleware — runs before auth to capture trace_id for all requests)
11. **E2** — Register `Queue::before` hook (job start logging only) in a NEW `QueueObservabilityServiceProvider`. `Queue::failing` deferred to job-refactoring commit
12. **E4** — Queue health check endpoint behind BasicAuth (same credentials as Horizon)

### Phase 5: Retry Tiers & Infrastructure
13. **A3** — Add `redis-long` queue connection (retry_after=10800s) for low queue; tighten default `redis` to retry_after=120s. Update supervisor-low connection in `config/horizon.php` to `redis-long`. Add `redis-long` connection in `config/queue.php` (same Redis server, same `queue` DB, just different retry_after)
14. **B2** — Verify/document Redis `maxmemory-policy` (Railway check)

### Phase 6: Optional
15. **G1** — Failover queue driver (evaluate cost/benefit)
16. **G2** — Prometheus (evaluate vs Sentry)

---

## Key Files to Modify

- `config/queue.php` — A1, A2
- `config/database.php` — B1
- `config/horizon.php` — C2 (Horizon `use` key stays unchanged — it's for metadata, not queue data)
- `app/Providers/HorizonServiceProvider.php` — C3
- `app/Providers/Schedule/` — D1, D2 (new `QueueMaintenanceScheduleServiceProvider`)
- `app/Providers/QueueObservabilityServiceProvider.php` — E2 (NEW file, Queue::before only)
- `config/queue.php` — A3 (add redis-long connection)
- `config/horizon.php` — A3 (update supervisor-low connection)
- `app/Presentation/Http/Middleware/SetRequestContextMiddleware.php` — E1 (NEW file — separate from RLS middleware)
- `routes/api.php` or `routes/web.php` — E4

---

## Verification

After each change:
- `make lint` — ensure no static analysis regressions
- `make test` — ensure no test failures
- For config changes: verify with `php artisan config:show queue`, `php artisan config:show database`
- For scheduling: `php artisan schedule:list` to verify new entries
- For Horizon: review dashboard after deploy

---

## Resolved Questions

1. **Slack webhook** — ✅ Available. Will route Horizon `LongWaitDetected` to Slack.
2. **Railway deploy** — ✅ Auto-restarts all services on deploy. Horizon gets fresh code automatically. F1 is resolved — no action needed.
3. **Railway Redis `maxmemory-policy`** — ❓ Unknown. Will document as a DevOps check item (B2).

## Decisions Made

- **F1 removed** — Railway auto-restarts, no verification needed.
- **C3 confirmed** — Slack webhook available, will implement notification routing.
- **B2 stays as documentation task** — check Railway Redis config when possible.
- **E2** → New `QueueObservabilityServiceProvider` with `Queue::before` only. `Queue::failing` and E3 (Sentry) deferred — jobs already have `failed()` methods with logging/notifications; global failure hooks would cause duplicates.
- **E4** → Behind BasicAuth (same as Horizon credentials).
- **A3** → Yes, add `redis-long` connection for low queue with separate retry_after. Tighten default redis to 120s.
- **E1** → New separate `SetRequestContextMiddleware` (runs before auth, distinct from RLS middleware).
- **Horizon `use` key** → Stays as `'default'` (controls metadata, not queue data).

**Total: 14 implementation tasks + 1 documentation task + 2 optional evaluations** (E3 + Queue::failing deferred — existing job `failed()` methods already handle failure logging/notifications)
