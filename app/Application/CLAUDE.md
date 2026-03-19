# Application Layer

## Directory Structure for New Integrations

```
{IntegrationName}/
├── Services/                   # Caching decorators, business logic with state
├── UseCases/                   # Entry points (orchestration, thin)
├── Queries/                    # Complex read params (optional, when needed)
├── Commands/                   # Write operations (optional, when needed)
└── Results/                    # Operation outcome objects (optional)
```

**When to use each:**
- **Services/**: Caching wrappers, stateful business logic, cross-concern coordination
- **UseCases/**: Single entry point per operation, orchestrates Services, stays thin
- **Queries/**: Parameter objects for complex read operations (e.g., `ConversationQueryParams`)
- **Commands/**: Parameter objects for write operations (future, when needed)
- **Results/**: Operation outcomes with success/failure tracking (e.g., `SyncResult`)

**Contracts**: Define interfaces in `Application/Contracts/{IntegrationName}/` when there are 2+ interfaces for an integration. Single interfaces can stay flat at `Application/Contracts/`.

---

## Jobs (`Application/Jobs/`)

Jobs live in the Application layer (not Presentation) because they orchestrate business logic and aren't tied to a specific delivery mechanism. They are in a Deptrac/PHPArkitect sub-layer (`ApplicationJobs`) with explicit Laravel framework access.

### Queue Priority Tiers

| Queue | Timeout | Use Case |
|-------|---------|----------|
| `high` | 90s | Time-sensitive, user-facing (webhooks, notifications) |
| `default` | 90s | Normal priority (order sync, daily jobs) |
| `low` | 3600s | Bulk/background work (full customer sync, data migrations) |

Route jobs via constructor: `$this->onQueue('low')`. Config: `config/horizon.php`.

### Required Job Properties

Every job must define: `$tries`, `$timeout`, `backoff()` (property or method), `failed()` method, and call `$this->onQueue()` in the constructor. Enforced by custom PHPStan rules in `DevTools/PHPStan/Rules/Jobs/`.

### Naming Convention

Job class names must start with: `Sync`, `Process`, `Reconcile`, `Set`, `Update`, or `Cleanup`.

---

## Logging

**PSR-3 `LoggerInterface` accepted in Application layer** — Log business events only (workflow milestones, coordination), not technical details. PSR-3 is a stable PHP-FIG interface.

## Interface Placement Rules

**Core Principle:** Interfaces live where they're USED, not where they're IMPLEMENTED.

- Application defines cross-layer contracts: `Application/Contracts/MixpanelClientInterface`
- Infrastructure implements: `MixpanelClient implements MixpanelClientInterface`
- Infrastructure may have internal-only interfaces (not crossing layer boundaries)
- Cross-layer interfaces in `/Contracts/` subdirectories within Domain or Application

**Why:** Dependency Inversion Principle — higher layers define contracts, lower layers fulfill them.

### Interface @throws Declarations

**Interfaces must declare all `@throws` from their implementations.** PHPStan cannot verify this — it has no body to analyse on interface methods, so under-declared `@throws` silently propagates incomplete exception information up the call chain.

| Implementation uses | Interface must declare |
|---|---|
| `DatabaseGateway::transact()` / `query()` | `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException` |
| External API transport (HTTP clients) | All translated domain exceptions the implementation can throw |

**Why:** If the interface under-declares, every UseCase and listener calling it inherits the gap. Jobs won't know about transient exceptions and can't retry appropriately.

---

## Exception Handling: Default is Don't Catch

Application **rarely catches** exceptions. It orchestrates Domain logic and lets exceptions bubble to Presentation.

**Why no try-catch by default?**
- Infrastructure already logged technical details
- Presentation will decide how to handle (retry, fail, respond)
- Adding try-catch duplicates logging with no value

### When Application SHOULD Catch

1. **Batch processing** — Business rule: "process all items, continue on individual failures." Catch per-item exceptions, accumulate results, return success/failure summary
2. **Transaction coordination** — Catch specific exceptions to rollback transactions and run cleanup. Different exceptions may need different cleanup (e.g., payment failure requires inventory release, stock failure just needs rollback). Always rethrow after cleanup
3. **Context transformation** — Catch infrastructure exception, wrap with richer business context, throw a more specific business exception

### Anti-Patterns

- ❌ Don't catch just to log — Infrastructure already logged technical details
- ❌ Don't wrap in generic exceptions (`SyncFailedException`) — loses context from the original domain exception

### Decision Tree
```
Exception in Use Case
    ↓
Need to handle multiple items where some can fail?
    → YES: Catch, continue processing, return results
    → NO: ↓

Need to coordinate transactions/cleanup?
    → YES: Catch specific exceptions, rollback/cleanup, rethrow
    → NO: ↓

Need to add business context?
    → YES: Catch, wrap with context, throw business exception
    → NO: DON'T CATCH
```

---

## Complex Use Case Reference

**`Shopwired/PricingUpdate/`** — Multi-phase batch orchestration pattern:

- Typed result objects (`SkippedPriceUpdateResult`, `FailedPriceUpdateResult`) over array shapes
- Phase-scoped results per step, merged via `PriceUpdateResult::fromPhases()` factory
- Single-item validation extracted with union return type, sorted by `match(true)` on `instanceof`
- `execute()` stays a thin 5-step pipeline delegating to focused private methods
