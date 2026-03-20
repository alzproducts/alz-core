# Infrastructure Jobs

Jobs are **queue delivery mechanisms** — they receive a queued message and invoke a UseCase, just like Controllers receive HTTP requests and invoke UseCases.

## Queue Priority Tiers

| Queue | Timeout | Use Case |
|-------|---------|----------|
| `high` | 90s | Time-sensitive, user-facing (webhooks, notifications) |
| `default` | 90s | Normal priority (order sync, daily jobs) |
| `low` | 3600s | Bulk/background work (full customer sync, data migrations) |

Route jobs via constructor: `$this->onQueue('low')`. Config: `config/horizon.php`.

## Required Job Properties

Every job must define: `$tries`, `$timeout`, `backoff()` (property or method), `failed()` method, and call `$this->onQueue()` in the constructor. Enforced by custom PHPStan rules in `DevTools/PHPStan/Rules/Jobs/`.

## Naming Convention

Job class names must start with: `Sync`, `Process`, `Reconcile`, `Set`, `Update`, or `Cleanup`.

## Dispatching

Application layer dispatches jobs via **dispatcher interfaces** (e.g., `ShopwiredSyncDispatcherInterface`) defined in `Application/Contracts/`. Dispatcher implementations in `Infrastructure/*/Dispatchers/` call `Job::dispatch()`.

This decoupling means:
- Application doesn't reference job classes
- Jobs can be replaced with inline execution for testing
- Queue mechanics stay in Infrastructure where they belong
