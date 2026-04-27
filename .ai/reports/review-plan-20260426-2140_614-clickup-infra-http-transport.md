# Implementation Plan: Deferred work from review of issue #614

**Review report:** `.ai/reports/review-20260426-2140_614-clickup-infra-http-transport.md`
**Tracked on issue:** https://github.com/alzproducts/alz-core/issues/614#issuecomment-4322137226

## Findings to Address

### 1. Task cache invalidation gap [HIGH]
- **Locations:**
  - `app/Infrastructure/ClickUp/Cache/ClickUpTasksCache.php` (cache class)
  - `app/Application/Contracts/ClickUp/ClickUpTasksCacheInterface.php` (contract)
  - `app/Application/ClickUp/UseCases/GetMyClickUpTasksUseCase.php` (refresh path)
  - `app/Application/ClickUp/UseCases/CompleteClickUpTaskUseCase.php` (caller of `forget()`)
  - `app/Application/ClickUp/UseCases/DeleteClickUpApiKeyUseCase.php` (caller of `forget()`)
  - `app/Application/ClickUp/Queries/ClickUpTaskQueryParams.php` (filter shape)
  - `app/Infrastructure/ClickUp/Clients/TasksClient.php::buildQuery()` (filter wire-conversion)
- **Fix:** Switch the cache strategy to **one entry per user** (drop filter dimensions from the cache key). Cache the full unfiltered task list for `userId`; apply `statuses`/`tags` filters in PHP after the cache read. Then `forget()` becomes a single `cache->forget("clickup:tasks:{userId}")` and works correctly for both `CompleteTaskUseCase` and `DeleteApiKeyUseCase`. ClickUp call drops `statuses[]`/`tags[]` query params (fetches all). `?refresh=true` works either by skip-the-read or by `forget()` before read — whichever the new shape makes natural.
- **Why:** Issue #614 success criteria explicitly call for `?refresh=true` invalidation; current no-op forget() also creates a 120 s UX bug on task complete and a stale-data window after key delete. Filter hashing in the key was over-engineered for a cache that holds a small, per-user list.

## Suggested Order

This is a single feature change spanning the cache class, its interface, three use cases, and the tasks client. Work order:

1. Update `ClickUpTaskQueryParams` (or keep, but document statuses/tags as in-PHP filters).
2. Rework `ClickUpTasksCache`: simplify key, real `forget()`, store/hydrate the full list shape.
3. Update `ClickUpTasksCacheInterface` to match (probably no signature change).
4. Have `TasksClient::buildQuery()` stop sending `statuses[]`/`tags[]` to ClickUp (or split into a separate method if the legacy filter is still wanted elsewhere).
5. Update `GetMyClickUpTasksUseCase` to apply statuses/tags filters in PHP after cache read; either skip-the-read on `forceRefresh` or call the new working `forget()`.
6. Update `CompleteClickUpTaskUseCase` and `DeleteClickUpApiKeyUseCase` to call the new `forget()` (no API change at the call site).
7. Update unit + feature tests: `ClickUpTasksCacheTest` (if/when added), `ClickUpTaskControllerTest` (refresh assertion).

The change is contained to the cache + tasks read/write paths; it does **not** touch the cipher, repository, exception hierarchy, or controller layer.
