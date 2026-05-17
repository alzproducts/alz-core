---
paths:
  - "app/Application/**/*UseCase.php"
---

# Application — UseCase Rules

## Logging

- DO open every `execute()` with an `info` log as its **first statement** — before guard clauses and early returns. **Why:** a silent early return is otherwise indistinguishable in logs from the use case never being invoked.
- DO close `execute()` with an `info` log immediately after the last side-effect. Include only data that is new at that point (e.g. IDs created or outcomes resolved during execution).

## Async Dispatch

- DO dispatch async work via **dispatcher interfaces** (e.g., `ShopwiredSyncDispatcherInterface`), never job classes directly (`SomeJob::dispatch()`). **Why:** Jobs live in `Infrastructure/Jobs/` — they are a delivery mechanism, not an Application concern.

## Typed Result Objects

- DO return a typed `{Feature}Result` object when the UseCase reports per-item outcomes (succeeded count + typed `*Skipped` / `*Failed` entries). Canonical: `CostPriceUpdateResult` (Linnworks), `PriceUpdateResult` (Shopwired).
- DO NOT return an array shape (`['failed' => [...], 'skipped' => [...]]`) — loses type safety and makes callers guess keys.

## Use Case Decomposition

- DO promote a UseCase to a feature subdirectory when private method count + `@throws` docblocks push the file past ~250 lines despite each method being under 20 lines.

**Feature subdirectory shape:**
```
{Integration}/{Feature}/
├── {Feature}UseCase.php              # Thin orchestrator — execute() pipeline + side-effects
├── {Feature}Transformer.php          # Pure static data transformations (partitioning, payload building)
├── {Name}Resolver.php                # Single-responsibility lookups (name→GUID, SKU→ID)
└── Results/                          # Operation outcome objects (optional, if feature-specific)
```

**Extraction guide:**
- **Transformers**: Pure static functions — transform, partition, deduplicate data. No dependencies, no side effects.
- **Resolvers**: Extract methods that call a single API/service to look up a value. Take only the dependency they need. Place in shared `{Integration}/Resolvers/` when reused across UseCases.
- **Keep in UseCase**: `execute()` pipeline, pre-flight validation, side-effects (DB writes, logging).
- **Avoid Services**: Only create Services for stateful logic or cross-concern coordination.

Canonical: `Linnworks/UpdateCostPriceBySupplier/` — bulk update with Transformer (partition/map/dedupe) + Resolver (in shared `Linnworks/Resolvers/`).
