---
paths:
  - "app/Infrastructure/**/Eloquent*Repository.php"
  - "app/Infrastructure/**/AbstractEloquentRepository.php"
---

# Eloquent Repository Rules

## Creating a New Repository

**All new repositories MUST:**
1. Define interface extending `RepositoryWriteInterface` in `Application/Contracts/`
2. Create implementation extending `AbstractEloquentRepository`

## Domain-to-Model Mapping

- DO call `Model::attributesFromDomain($vo)` — never inline field-by-field mapping in the repository
- DO merge parent FK with model attributes using spread: `['fk' => $id, ...Model::attributesFromDomain($vo)]`
- Canonical example: `StockItemSupplierModel::attributesFromDomain()`

## Database Access

Use `DatabaseGateway`, never the `DB::` facade directly. All `DatabaseGateway::transact()` / `query()` calls must be declared in `@throws` on the repository interface:

| Implementation uses | Interface must declare |
|---|---|
| `DatabaseGateway::transact()` / `query()` | `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException` |
