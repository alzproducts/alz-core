# Plan: ClickUp Backend Migration (issue #614)

**Date:** 2026-04-26
**Issue:** https://github.com/alzproducts/alz-core/issues/614
**Scope:** alz-core only (alz-admin migration is a follow-up PR)

---

## Design decisions (locked via /grill-me)

### 1. Encryption ownership
- `API_KEY_ENCRYPTION_SECRET` moves to alz-core; alz-admin no longer holds it
- Envelope unchanged: `iv:authTag:cipher` (hex-joined), AES-256-GCM
- Existing `user_api_keys` rows wiped on deploy (1 user; re-paste is 30s)
- Follow-up issue #1: harden envelope (libsodium + versioned + AAD bound to `user_id||service`)

### 2. Domain shape
- `App\Domain\Access\ValueObjects\ApiKeyToken` — typed wrapper for plaintext token
- `App\Domain\Access\Enums\ThirdPartyService` — `ClickUp = 'clickup'`, `HelpScout = 'helpscout'`
- `App\Domain\Exceptions\Api\MissingApiKeyException` extends `PermanentApiFailure` → HTTP 412
- No `ClickUpApiKey` or per-vendor VO in Domain — generic Access VOs only
- No Domain `Task` VO — pass-through via Spatie Data DTOs
- No Domain enums for ClickUp status/tag names — nothing in Domain or Application branches on those values; they flow through as opaque strings from frontend → use case → transport → ClickUp. The one literal we own (`'CLOSED'` for mark-complete) lives in `config/clickup.php`.

### 3. Application contracts
- `App\Application\Contracts\Access\UserApiKeyRepositoryInterface`
  ```php
  public function tokenForUser(Guid $userId, ThirdPartyService $service): ?ApiKeyToken;
  public function save(Guid $userId, ThirdPartyService $service, ApiKeyToken $token): void;
  public function delete(Guid $userId, ThirdPartyService $service): void;
  ```
- `App\Application\Contracts\Access\ApiKeyCipherInterface`
  ```php
  public function encrypt(ApiKeyToken $token): string;   // returns ciphertext
  public function decrypt(string $ciphertext): ApiKeyToken;
  ```
- Contracts live in `Application/Contracts/Access/` (cross-layer, used by Application, implemented by Infrastructure)

### 4. Application use cases
```
app/Application/ClickUp/UseCases/
  SaveClickUpApiKeyUseCase.php       # validates against ClickUp /user BEFORE writing
  DeleteClickUpApiKeyUseCase.php
  GetClickUpApiKeyInfoUseCase.php    # for GET /api/clickup/api-key
  GetMyClickUpTasksUseCase.php
  CompleteClickUpTaskUseCase.php

app/Application/ClickUp/Queries/
  ClickUpTaskQueryParams.php         # list<string> $statuses, list<string> $tags, bool $forceRefresh
                                     # opaque strings — backend doesn't enumerate ClickUp's workspace vocabulary
```

### 5. Infrastructure layout
```
app/Infrastructure/Access/
  EloquentUserApiKeyRepository.php   # UserApiKeyModel + cipher
  OpensslApiKeyCipher.php            # openssl_decrypt/encrypt, iv:authTag:cipher format

app/Infrastructure/ClickUp/
  ClickUpClientFactory.php
  ClickUpConfig.php
  ClickUpHttpTransport.php           # final readonly; takes ApiKeyToken per call (Octane-safe)
  ClickUpErrorHandler.php            # static; mirrors HelpScout + adds 404→ResourceNotFoundException
  Clients/
    UsersClient.php                  # GET /user (validate + get assignee ID)
    TasksClient.php                  # GET /list/{id}/task, PUT /task/{id} (body: { status })
  Responses/
    AuthenticatedClickUpUserResponse.php   # Spatie Data
    TaskResponse.php                       # Spatie Data
  Cache/
    ClickUpUserIdCache.php           # Redis, 24h TTL, key: "clickup:user_id:{supabaseUuid}"
    ClickUpTasksCache.php            # Redis, 120s TTL, key: "clickup:tasks:{userId}:{statusesHash}:{tagsHash}"

config/clickup.php
  # base_url
  # list_id           (env-overridable: CLICKUP_LIST_ID)
  # complete_status   (env-overridable: CLICKUP_COMPLETE_STATUS, default 'CLOSED')
  #                   — read by CompleteClickUpTaskUseCase; the only ClickUp status literal alz-core owns
  # timeout_seconds
```

#### HTTP transport pattern (per-call auth — different from HelpScout's tenant-wide OAuth)
```php
// Transport methods accept ApiKeyToken directly; singleton is Octane-safe.
// ClickUp uses PUT for task updates so the transport must expose at least get/post/put,
// or a generic send($method, ...) per the HelpScout precedent.
public function get(ApiKeyToken $token, string $endpoint, array $query = []): Response
public function post(ApiKeyToken $token, string $endpoint, array $body = []): Response
public function put(ApiKeyToken $token, string $endpoint, array $body = []): Response
```

#### Error mapping matrix (ClickUpErrorHandler)
| HTTP status | Domain exception |
|---|---|
| 400, 422 | `InvalidApiRequestException` |
| 401, 403 | `AuthenticationExpiredException` |
| 404 | `ResourceNotFoundException` |
| 429 | `ExternalServiceUnavailableException` (with Retry-After) |
| 5xx / connection | `ExternalServiceUnavailableException` |
| Unparseable body | `InvalidApiResponseException` |

### 6. Caching strategy
- **ClickUp user ID** — Redis 24h. Eager-populate in `SaveClickUpApiKeyUseCase` (which already calls `/user`). Lazy fallback in `GetMyClickUpTasksUseCase` on cache miss.
- **Task list** — Redis 120s. Key: `(userId, statuses, tags)`. `?refresh=true` invalidates before fetching.
- No DB schema changes for caching. Redis is ephemeral; if cold, tasks fall back to a live ClickUp call.
- Follow-up issue #2: `external_services` + `external_service_users` schema to persist ClickUp user IDs properly.

### 7. Presentation layer
```
app/Presentation/Http/Api/Controllers/ClickUp/
  ClickUpAuthController.php    # POST (save), GET (info), DELETE — api-key resource
  ClickUpTaskController.php    # GET (tasks), POST /{id}/complete — task resource

app/Presentation/Http/Api/DTOs/ClickUp/
  SaveClickUpApiKeyRequest.php          # Spatie Data; { apiKey: string }

app/Presentation/Http/Api/Resources/ClickUp/
  ClickUpApiKeyInfoResource.php         # hasKey, maskedKey, lastUsedAt, clickupUserEmail
  ClickUpTaskResource.php               # id, name, status, dueDate, tags[], url
```

### 8. Routes (append to `routes/api.php`, inside JWT + EnsureUserApproved + Sentry group)
```php
Route::prefix('clickup')->group(static function (): void {
    // api-key management
    Route::post('api-key', [ClickUpAuthController::class, 'save']);   // ?dry_run=true
    Route::get('api-key', [ClickUpAuthController::class, 'info']);
    Route::delete('api-key', [ClickUpAuthController::class, 'delete']);

    // tasks
    Route::get('tasks', [ClickUpTaskController::class, 'index']);
    Route::post('tasks/{taskId}/complete', [ClickUpTaskController::class, 'complete']);
});
```

### 9. Middleware tightening (in same PR)
- Add `EnsureUserApprovedMiddleware` to the existing `/helpscout/*` group in `routes/api.php`
- This closes the asymmetry: both dashboard widget groups now require approval
- Check HelpScout feature tests ensure the test user is marked approved (add fixture if not)

### 10. Testing
- **Unit** — `OpensslApiKeyCipher`:
  - Round-trip: `decrypt(encrypt(token)) === token`
  - Cross-language fixture: commit a Node-encrypted ciphertext (`tests/fixtures/api-key-cipher-fixture.txt`); test asserts PHP decrypts to the known plaintext with the known key
- **Unit** — `ClickUpErrorHandler`: translation matrix (mirror HelpScout's handler test)
- **Feature** — Each controller endpoint:
  - `Http::fake()` stubs ClickUp responses
  - Seeded `user_api_keys` row for the test user
  - Happy path + 412 when no key configured + cache behaviour
- **Feature** — `?dry_run=true` on POST api-key validates but doesn't write

### 11. Wipe-on-deploy note
On the deploy that ships this PR, manually run:
```sql
DELETE FROM public.user_api_keys WHERE service = 'clickup';
```
One user affected; re-paste key in Settings after deploy.

### 12. Wiring checklist (easy to miss)
- Service provider bindings (e.g. `AppServiceProvider` or new `ClickUpServiceProvider`):
  - `UserApiKeyRepositoryInterface` → `EloquentUserApiKeyRepository`
  - `ApiKeyCipherInterface` → `OpensslApiKeyCipher`
- Env var to add to alz-core: `API_KEY_ENCRYPTION_SECRET` (64 hex chars / 32 bytes), copied from alz-admin's env. Optional `CLICKUP_LIST_ID` override consumed by `config/clickup.php`.
- `ClickUpTasksCache` key derivation must sort `statuses` and `tags` arrays before hashing — otherwise equivalent requests miss the cache.
- Plaintext `ApiKeyToken` value must be excluded from logs and Sentry breadcrumbs (e.g. via Sentry `before_send` scrubber or by ensuring it's never part of an exception message / log context array).

---

## Follow-up issues to file alongside this PR

### Follow-up #1 — Envelope hardening
**Title:** `chore(security): harden user_api_keys encryption envelope`
**Summary:** Current `iv:authTag:cipher` AES-256-GCM format has no version tag, no AAD, and is a bespoke cross-language format. Migrate to versioned envelope with AAD bound to `(user_id, service)`. Evaluate libsodium `secretbox` for single-function correctness.

### Follow-up #2 — External service identity schema
**Title:** `feat(auth): external_services and external_service_users schema`
**Summary:** `external_user_id` doesn't belong on `user_api_keys`. Design and implement `external_services` (registry) + `external_service_users` (per-user identity mapping) tables. Migrate the Redis-cached ClickUp user ID into the new table. Rationale: identity mapping is a distinct concern from credential storage.

---

## What is explicitly NOT in this PR
- alz-admin frontend migration (separate PR)
- Schema changes to `user_api_keys` beyond data wipe
- `external_services` / `external_service_users` table creation
- HelpScout key handling (HelpScout uses tenant-wide OAuth; unaffected)
- New ClickUp features beyond GET tasks / complete task / api-key CRUD
- Envelope hardening / libsodium migration
