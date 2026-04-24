---
paths:
  - "app/Application/Contracts/**/*Repository*.php"
---

# Repository Contract Interface Rules

## @throws Declarations

- DO declare every `@throws` the implementation can raise — PHPStan cannot verify `@throws` on interface methods, so gaps silently propagate up the call chain
- DO include `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException` on any interface whose implementation calls `DatabaseGateway::transact()` / `query()` — applies to both write and query interfaces
- DO include every translated domain exception the implementation can throw on interfaces backed by external-API transport

## Write Repositories — `*RepositoryInterface.php`

- DO extend `RepositoryWriteInterface`
- DO expose only write operations (`save`, `delete`, `upsert`, etc.)
- EXCEPTION — infrastructure-state repositories (sync cursors, singleton config) MAY omit `RepositoryWriteInterface`. Canonical: `SyncCursorRepositoryInterface`

## Query Repositories — `*QueryRepositoryInterface.php`

- DO NOT extend `RepositoryWriteInterface`
- DO NOT declare `save()`, `update()`, `delete()`, or `upsert()`
- DO pair the implementation with a `*ViewModel` under `app/Infrastructure/**/Models/` (typically backed by a PostgreSQL view)
