---
paths:
  - "app/Infrastructure/**/Eloquent*Repository.php"
  - "app/Infrastructure/**/AbstractEloquentRepository.php"
---

# Eloquent Repository Rules

## Check EloquentGateway First

Before inlining `$modelClass::query()`, `transact()`, or adding a new helper method, check `$this->eloquentGateway` for an existing method that covers the operation.

**Why:** Inline closures duplicate the read-vs-write transaction distinction the gateway already encodes — picking `query()` instead of `transact()` for a write is unreachable when you go through `upsertOne` / `deleteWhere*`. If no gateway method fits a compound predicate, prefer adding a primitive to `EloquentGateway` over inlining.

## Inline query() vs transact() — when no gateway method fits

If a one-off case genuinely requires `$this->eloquentGateway->query()` or `->transact()` directly (see rule above):

- DO call `transact()` for writes. Wraps the operation in a transaction with retry on deadlock.
- DO call `query()` for reads. No transaction overhead.

## Creating a New Repository

- DO declare the class `final` extending `AbstractEloquentRepository` and implementing `<Thing>RepositoryInterface` (the interface extends `RepositoryWriteInterface` in `Application/Contracts/`)
- EXCEPTION — persisting infrastructure state (sync cursors, singleton config), not a domain entity: inject `EloquentGateway` directly in a `final readonly class`; do NOT extend `AbstractEloquentRepository`. Canonical: `EloquentSyncCursorRepository`

## Save Method Overrides

- DO NOT override `save()` unless the entity has child relations
- DO NOT override `saveMany()` unless coordinating multi-table transactions
- DO call the inherited protected `saveManyBulk()` helper from an overridden `saveMany()` for flat-entity bulk upsert

## Domain-to-Model Mapping (writes)

- DO NOT inline field-by-field mapping
- DO call `Model::fromDomainAttributes($entity)` (interface-compliant) or `Model::attributesFromDomain($typed)` (typed-parameter form) — both naming conventions exist in the codebase
- DO spread the mapper output alongside any parent FK or upsert key the repository adds

## Model-to-Domain Mapping (reads)

- DO call `$model->toDomain()` — the model owns its read-path projection.
- DO NOT define a private `fromModel()`, `toEntity()`, or equivalent on the repository.
- EXCEPTION: complex models with loaded Eloquent relations or injected dependencies that the model cannot hold: delegate to a standalone `*ModelMapper` class instead. Canonical: `OrderModelMapper::fromModelWithRelations()`, `ProductModelMapper`.

## Database Access

- DO NOT use the `DB::` facade directly; use `DatabaseGateway` / `EloquentGateway`
- DO declare every exception thrown by `DatabaseGateway::transact()` / `query()` on the interface's `@throws`: `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`
