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

## Async Dispatch

Application dispatches async work via **dispatcher interfaces** (e.g., `ShopwiredSyncDispatcherInterface`), not job classes directly. Jobs live in `Infrastructure/Jobs/` — see `app/Infrastructure/Jobs/CLAUDE.md`.

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

### Client Interface Design: Pre-Resolved Parameters

Application-layer client interfaces accept **pre-resolved** identifiers and domain values. The UseCase orchestrates all resolution (SKU→stockItemId, supplierName→GUID) via dedicated Resolver classes before calling the client.

**Why:** Resolution is orchestration — it involves business decisions (batch vs single, caching, error handling). Infrastructure clients are structural mappers, not orchestrators.

```
UseCase: resolve IDs → pass pre-resolved values to interface
Client:  receive pre-resolved values → structural mapping → transport
```

- ✅ Interface params: `Guid $supplierGuid`, `array<string, Money> $prices`
- ❌ Interface params: `string $supplierName` (requires resolution inside client)

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

## Use Case Decomposition

When a UseCase approaches the 250-line class limit, promote it to a **feature subdirectory** with focused helper classes:

```
{Integration}/{Feature}/
├── {Feature}UseCase.php              # Thin orchestrator — execute() pipeline + side-effects
├── {Feature}Transformer.php          # Pure static data transformations (partitioning, payload building)
├── {Name}Resolver.php                # Single-responsibility lookups (name→GUID, SKU→ID)
└── Results/                          # Operation outcome objects (optional, if feature-specific)
```

**Extraction guide:**
- **Transformers**: Group pure static functions that transform/partition/deduplicate data. No dependencies, no side effects.
- **Resolvers**: Extract methods that call a single API/service to look up a value. Take the dependency they need.
- **Keep in UseCase**: `execute()` pipeline, pre-flight validation, side-effects (DB writes, logging)
- **Avoid full Services**: Only create Services for stateful logic or cross-concern coordination

**Trigger**: When private method count + `@throws` docblocks push the file past ~250 lines despite each method being under 20 lines.

**Reference**: `Linnworks/UpdateCostPriceBySupplier/` — bulk update with Transformer (partition/map/dedupe) + Resolver (in shared `Linnworks/Resolvers/`).

## Complex Use Case Reference

**`Shopwired/PricingUpdate/`** — Multi-phase batch orchestration pattern:

- Typed result objects (`SkippedPriceUpdateResult`, `FailedPriceUpdateResult`) over array shapes
- Phase-scoped results per step, merged via `PriceUpdateResult::fromPhases()` factory
- Single-item validation extracted with union return type, sorted by `match(true)` on `instanceof`
- `execute()` stays a thin 5-step pipeline delegating to focused private methods
