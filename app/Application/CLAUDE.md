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

## Logging

**PSR-3 `LoggerInterface` accepted in Application layer** — Log business events only (workflow milestones, coordination), not technical details. PSR-3 is a stable PHP-FIG interface.

## Interface Placement Rules

**Core Principle:** Interfaces live where they're USED, not where they're IMPLEMENTED.

- Application defines cross-layer contracts: `Application/Contracts/MixpanelClientInterface`
- Infrastructure implements: `MixpanelClient implements MixpanelClientInterface`
- Infrastructure may have internal-only interfaces (not crossing layer boundaries)
- Cross-layer interfaces in `/Contracts/` subdirectories within Domain or Application

**Why:** Dependency Inversion Principle — higher layers define contracts, lower layers fulfill them.

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

- Phase-scoped results per step, merged via `PriceUpdateResult::fromPhases()` factory
- Single-item validation extracted with union return type, sorted by `match(true)` on `instanceof`

## Per-File Conventions

See `.claude/rules/` for file-type-specific rules (auto-load on matching globs):
- `application-use-cases.md` — `*UseCase.php`: decomposition trigger, async dispatch, typed result objects
- `application-client-interfaces.md` — `*ClientInterface.php`: pre-resolved parameters
- `repository-contracts.md` — `*Repository*.php`: interface `@throws` declarations
