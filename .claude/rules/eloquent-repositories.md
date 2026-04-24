---
paths:
  - "app/Infrastructure/**/Eloquent*Repository.php"
  - "app/Infrastructure/**/AbstractEloquentRepository.php"
---

# Eloquent Repository Rules

## Check EloquentGateway First

Before inlining `$modelClass::query()`, `transact()`, or adding a new helper method, check `$this->eloquentGateway` for an existing method that covers the operation.

## Creating a New Repository

- DO declare the class `final` extending `AbstractEloquentRepository` and implementing `<Thing>RepositoryInterface` (the interface extends `RepositoryWriteInterface` in `Application/Contracts/`)
- EXCEPTION — persisting infrastructure state (sync cursors, singleton config), not a domain entity: inject `EloquentGateway` directly in a `final readonly class`; do NOT extend `AbstractEloquentRepository`. Canonical: `EloquentSyncCursorRepository`

## Save Method Overrides

- DO NOT override `save()` unless the entity has child relations
- DO NOT override `saveMany()` unless coordinating multi-table transactions
- DO call the inherited protected `saveManyBulk()` helper from an overridden `saveMany()` for flat-entity bulk upsert

## Domain-to-Model Mapping

- DO NOT inline field-by-field mapping
- DO call `Model::fromDomainAttributes($entity)` (interface-compliant) or `Model::attributesFromDomain($typed)` (typed-parameter form) — both naming conventions exist in the codebase
- DO spread the mapper output alongside any parent FK or upsert key the repository adds

## Database Access

- DO NOT use the `DB::` facade directly; use `DatabaseGateway` / `EloquentGateway`
- DO declare every exception thrown by `DatabaseGateway::transact()` / `query()` on the interface's `@throws`: `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`
