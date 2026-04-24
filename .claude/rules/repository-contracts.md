---
paths:
  - "app/Application/Contracts/**/*Repository*.php"
---

# Repository Contract Interface Rules

## Shared: @throws Declarations

**DO declare every `@throws` the implementation can raise.** PHPStan cannot verify `@throws` on interface methods, so gaps silently propagate up the call chain.

| Implementation uses | Interface must declare |
|---|---|
| `DatabaseGateway::transact()` / `query()` | `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException` |
| External API transport (HTTP clients) | All translated domain exceptions the implementation can throw |

Both write and query interfaces call through `DatabaseGateway`, so both carry the DB `@throws`.

## Write Repositories — `*RepositoryInterface.php`

(Any `*RepositoryInterface.php` that is NOT `*QueryRepositoryInterface.php`.)

- Extends `RepositoryWriteInterface`
- Paired with an `Eloquent*Repository` implementation under `app/Infrastructure/**/Repositories/`
- Exposes write operations (`save`, `delete`, `upsert`, `insertBatch`, etc.)

## Query Repositories — `*QueryRepositoryInterface.php`

- **Read-only projections** — no `save()`, `update()`, `delete()`, `upsert()` on the interface
- Typically backed by a PostgreSQL view (e.g. `ProductViewQueryRepositoryInterface` → `catalog.products_view`)
- Does **NOT** extend `RepositoryWriteInterface`
- Implementation pairs with a `*ViewModel` under `app/Infrastructure/**/Models/`
