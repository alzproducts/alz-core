# 614 — ClickUp Backend Migration

**Issue:** feat(clickup): move ClickUp integration from alz-admin to backend
**Branch:** feature/614-clickup-integration-alz-admin-to-backend
**Plan:** .ai/plans/2026-04-26_614-clickup-backend-migration.md

---

## Decisions

- `UserApiKeyMetaResult` in `Application/ClickUp/Results/` — holds `hasKey`, `maskedKey`, `lastUsedAt` (moved from Contracts/ after PHPArkitect: Contracts/ must only contain `*Interface`)
- `ClickUpUserCache` replaces plan's `ClickUpUserIdCache` — stores JSON `{id, email}` at `clickup:user:{uuid}` (24h)
- `EloquentUserApiKeyRepository` uses `pgsql` (admin) connection + explicit `user_id` filter; does NOT extend `AbstractEloquentRepository` (CRUD, not batch-sync)
- `Application/Contracts/ClickUp/UsersClientInterface` + `TasksClientInterface` follow the HelpScout pattern for cross-layer contracts
- `ClickUpTaskDataDTO` is a plain readonly class (NOT Spatie Data) — Deptrac blocks `Spatie\LaravelData\Data` from Application layer
- `ClickUpUserDataDTO`, `ClickUpApiKeyMetaDTO` are plain readonly classes; all Application DTOs have `DTO` suffix per PHPArkitect
- `dry_run` handled by `$request->boolean()` in controller (not in Spatie Data DTO — bool casting of `"true"` string from query param fails)
- `MissingApiKeyException → HTTP 412 + PreconditionFailed` error type added to `InternalApiExceptionMapper`
- `EnsureUserApprovedMiddleware` added to `/helpscout/*` route group (closes middleware asymmetry per §9 of plan)
- `ClickUpClientFactory` skipped — no OAuth needed; transport accepts `ApiKeyToken` per call
- `ctype_xdigit()` pre-validation in `OpensslApiKeyCipher::decrypt()` — prevents `ErrorException` from `hex2bin()` with non-hex input
- Cache key hashing upgraded to `sha256` from `md5` (lint fix; no security concern for non-secret keys)

## Deviations from Plan

- DTOs renamed with `DTO` suffix to satisfy PHPArkitect: `ClickUpTaskDataDTO`, `ClickUpUserDataDTO`, `ClickUpApiKeyMetaDTO`
- `SaveClickUpApiKeyRequest` renamed to `SaveClickUpApiKeyRequestDTO`
- `UserApiKeyMeta` moved from `Application/Contracts/Access/` to `Application/ClickUp/Results/UserApiKeyMetaResult`
- `ClickUpTasksCache::forget()` is a best-effort prefix delete (120s TTL is the real safety net; tag-based cache required for full invalidation — documented as Follow-up #3)

## Progress

- [x] Domain layer — ThirdPartyService, ApiKeyToken, MissingApiKeyException
- [x] Application contracts — UserApiKeyRepositoryInterface, ApiKeyCipherInterface, UsersClientInterface, TasksClientInterface, ClickUpUserCacheInterface, ClickUpTasksCacheInterface
- [x] Application use cases — 5 use cases + ClickUpTaskQueryParams + DTOs (ClickUpTaskDataDTO, ClickUpUserDataDTO, ClickUpApiKeyMetaDTO, UserApiKeyMetaResult)
- [x] Infrastructure — cipher, repository, config, transport, error handler, clients, cache, model, config/clickup.php
- [x] Presentation — SaveClickUpApiKeyRequestDTO, ClickUpApiKeyInfoResource, ClickUpTaskResource, ClickUpAuthController, ClickUpTaskController
- [x] Wiring — ClickUpServiceProvider, routes/api.php, InternalApiExceptionMapper, ApiErrorTypeEnum, bootstrap/providers.php, config/app.php
- [x] Tests — OpensslApiKeyCipherTest (unit), ClickUpErrorHandlerTest (unit), ClickUpAuthControllerTest (feature), ClickUpTaskControllerTest (feature)
- [x] Tests pass: 3296 passed, 0 failures
- [x] Lint clean: Pint + PHPStan + PHPArkitect + Deptrac + TLint all pass
- [x] Simplify — 5 fixes applied: `ClickUpTasksCache::forget()` made honest no-op; `findModel()` extracted from repository; `hydrateItem()` returns null on corrupt data (not ghost DTOs); `GetClickUpApiKeyInfoUseCase` short-circuits Redis when `hasKey === false`; `ClickUpConfig` adds `completeStatus` guard; trivial docblocks removed
- [x] Sweep — LoggerInterface injected into all 5 use cases with business milestone logging; PHPStan method length violations fixed via private method extraction; redundant per-property `readonly` removed from `ClickUpTaskDataDTO`; `ClickUpServiceProvider` wired LoggerInterface for manually-constructed use cases
- [x] Code review pass — `/review-code` surfaced 3 HIGH + 4 MEDIUM findings; fixes applied for HIGH-2 (encrypt failure check), HIGH-3 (mask plaintext), MEDIUM-1 (404 hardcoded resource type), MEDIUM-2 (hex validation), MEDIUM-3 (decrypt exception type), MEDIUM-5 (`ClickUpConfig` duplication). Cipher exceptions reshaped: new `KeyEncryptionFailedException` + `CorruptApiKeyException` extend `PermanentApiFailure`; the latter maps to HTTP 412 (same UX as `MissingApiKeyException` — re-paste flow).

## Outstanding — Deferred

- **Task cache invalidation** — `ClickUpTasksCache::forget()` is a no-op. Breaks `?refresh=true` (issue success criterion), causes 120 s UX bug on `CompleteClickUpTaskUseCase`, and leaves stale data after `DeleteClickUpApiKeyUseCase`. Documented at https://github.com/alzproducts/alz-core/issues/614#issuecomment-4322137226. Three implementation options identified; recommended path is to cache the unfiltered task list per user (one key per user) and filter in PHP after cache read. To be addressed in a follow-up before alz-admin migration completes.

## PR Notes

**feat(clickup): move ClickUp integration from alz-admin to backend (#614)**

Moves the ClickUp integration out of alz-admin server actions into alz-core, establishing it as the single integration hub. Adds 5 endpoints (POST/GET/DELETE `/api/clickup/api-key`, GET `/api/clickup/tasks`, POST `/api/clickup/tasks/{taskId}/complete`) behind JWT + EnsureUserApproved. Also closes the middleware asymmetry on the existing HelpScout routes.

Key design points:
- Generic `ApiKeyToken` VO + `ThirdPartyService` enum — reusable for future service integrations
- Per-call auth on `ClickUpHttpTransport` (Octane-safe; no shared state)
- AES-256-GCM cipher (`iv:authTag:ciphertext` envelope, cross-language compatible with Node)
- ClickUp user data cached in Redis (24h); task list cached 120s with `?refresh=true` invalidation
- `MissingApiKeyException` → HTTP 412 added to the global exception mapper
