# Infrastructure Jobs

Jobs are **queue delivery mechanisms** — they receive a queued message and invoke a UseCase, just like Controllers receive HTTP requests and invoke UseCases.

## Queue Priority Tiers

| Queue | Timeout | Use Case |
|-------|---------|----------|
| `high` | 90s | Time-sensitive, user-facing (webhooks, notifications) |
| `default` | 90s | Normal priority (order sync, daily jobs) |
| `low` | 3600s | Bulk/background work (full customer sync, data migrations) |
| `bulk` | 60s | High-volume single-item jobs (free delivery updates) |
| `background` | 43200s | Ultra-long-running jobs (historical backfills, full PO syncs) |

Route jobs via constructor: `$this->onQueue('low')`. Config: `config/horizon.php`.

## Required Job Properties

Every job must define: `$tries`, `$timeout`, `backoff()` (property or method), and call `$this->onQueue()` in the constructor. Jobs using `HandleApiExceptions` middleware don't need `failed()` — only define it for side effects (e.g. marking a DB record as failed). Enforced by custom PHPStan rules in `DevTools/PHPStan/Rules/Jobs/`.

## Logging

Jobs should rarely log. Log where context is most relevant — if a job passes input params straight to a UseCase, the UseCase should log them, not the job. `Queue::before`/`Queue::after`/`Queue::failing` handle start/completion/failure logging for all jobs centrally.

## Naming Convention

Job class names must start with: `Sync`, `Process`, `Reconcile`, `Set`, `Update`, or `Cleanup`.

## Dispatching

Application layer dispatches jobs via **dispatcher interfaces** (e.g., `ShopwiredSyncDispatcherInterface`) defined in `Application/Contracts/`. Dispatcher implementations in `Infrastructure/*/Dispatchers/` call `Job::dispatch()`.

This decoupling means:
- Application doesn't reference job classes
- Jobs can be replaced with inline execution for testing
- Queue mechanics stay in Infrastructure where they belong
