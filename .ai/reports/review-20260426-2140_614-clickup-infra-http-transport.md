# Code Review: Issue #614 — ClickUp Infrastructure HTTP transport layer

**Date:** 2026-04-26
**Branch:** feature/614-clickup-integration-alz-admin-to-backend
**Base:** origin/develop
**Scope:** New HTTP transport, error handler, clients, caches, cipher, Eloquent repository, related Domain exceptions and Application contracts.
**Files reviewed:** 17

## Findings

### CRITICAL
None.

### HIGH
- **HIGH-1** [`app/Infrastructure/ClickUp/Cache/ClickUpTasksCache.php:75-79` + `app/Application/ClickUp/UseCases/GetMyClickUpTasksUseCase.php:48-59`] — `ClickUpTasksCache::forget()` is a documented no-op; `?refresh=true` does NOT invalidate the cached task list, breaking an explicit issue success criterion. Same no-op also affects `CompleteClickUpTaskUseCase` (120 s window where a just-completed task reappears) and `DeleteClickUpApiKeyUseCase` (cached tasks linger after key delete). **Status: Deferred** — documented at https://github.com/alzproducts/alz-core/issues/614#issuecomment-4322137226 and tracked in the implementation log; recommended fix is to cache the unfiltered list per user (one cache key per user) and filter in PHP.
- **HIGH-2** [`app/Infrastructure/Access/OpensslApiKeyCipher.php:48-58`] — `openssl_encrypt()` return value not checked; on failure, `false` cast to `''` would store a truncated envelope `{iv}:{tag}:` and silently destroy the user's key. **Status: Fixed.** Encrypt now throws `KeyEncryptionFailedException` on `false` return and on IV-generation failure.
- **HIGH-3** [`app/Infrastructure/Access/EloquentUserApiKeyRepository.php:60-74,125-130`] — `buildMaskedKey()` masked the AES-GCM **ciphertext**, not the plaintext, so the mask changed on every save and gave the user random hex bytes that couldn't answer "is this my key?". **Status: Fixed.** `metaForUser()` now decrypts the stored ciphertext and uses `ApiKeyToken::masked()` (existing plaintext masking on the VO). On decrypt failure, `CorruptApiKeyException` propagates and the mapper renders 412 — same UX as `MissingApiKeyException` (re-paste).

### MEDIUM
- **MEDIUM-1** [`app/Infrastructure/ClickUp/ClickUpErrorHandler.php:81`] — 404 handler hardcoded `resource_type: 'task'`, but the transport is generic; a 404 from `/user` (key validation) was misreported as a missing *task*. **Status: Fixed.** `resource_type` is now `'unknown'`; the endpoint URL is included in `resource_id` and the log context for triage.
- **MEDIUM-2** [`app/Infrastructure/Access/OpensslApiKeyCipher.php:35-38`] — Constructor accepted any 64-char string and silently fell through to an empty key on non-hex input via `(string) hex2bin(false)`. **Status: Fixed.** Constructor now validates length **and** `ctype_xdigit`, throwing `InvalidConfigurationException`. The provider's pre-flight length check has been removed in favour of the constructor invariant.
- **MEDIUM-3** [`app/Infrastructure/Access/EloquentUserApiKeyRepository.php:51`] — `tokenForUser` translated decrypt failure (`InvalidApiResponseException`) into `DatabaseOperationFailedException`, mis-attributing a cipher failure to the database. **Status: Fixed.** New `CorruptApiKeyException extends PermanentApiFailure` is thrown by the cipher and propagates through the repository unchanged. Mapper renders it as 412 with a distinct `cipher_corrupted` error type.
- **MEDIUM-4** [`config/clickup.php:8-10`] — `list_id` hardcoded with no env override (plan called for `CLICKUP_LIST_ID`). **Status: Skipped per user** — confirmed the canonical list ID is the correct implementation; not a bug.
- **MEDIUM-5** [`app/Infrastructure/ClickUp/ClickUpConfig.php`, `app/Providers/ClickUpServiceProvider.php`] — `ClickUpConfig` carried `listId` and `completeStatus` solely for fail-fast validation while the same values were passed separately to the use cases — two paths to one value. **Status: Fixed.** `ClickUpConfig` now holds only transport-level fields (`baseUrl`, `timeoutSeconds`). `listId` and `completeStatus` are validated via `requireConfigString()` directly in the provider's use-case bind closures.

### LOW
- 5xx handler does not parse `Retry-After` (consistent with HelpScout precedent — left intentionally).
- `TaskResponse::tags` docblock narrower than reality (`array<string, string>` vs. `array<string, mixed>`); cosmetic, doesn't affect runtime.
- Generic `catch (Exception $e)` in clients for parse errors; could narrow to `CannotCreateData`. Style preference.

## Positive Observations

- **Octane-safe per-call auth** on `ClickUpHttpTransport` — `ApiKeyToken` threaded into each method rather than constructor-bound, so a single transport singleton serves concurrent users without shared state.
- **Clean exception hierarchy** — every Domain exception in this PR sits in the existing `AbstractApiException → PermanentApiFailure / TransientApiFailure` shape, so jobs and the global mapper handle them without special-casing.
- **DTO conventions** — Spatie response DTOs implement the project's `DtoConvertibleInterface` for Application-DTO targets, distinct from the `DomainConvertibleInterface` used for Domain VO targets. Per-file `.claude/rules/infrastructure-response-dtos.md` is honoured.

## Summary

The new ClickUp Infrastructure layer follows the HelpScout precedent closely and got the architectural fundamentals right (Octane-safe auth, layer-correct interfaces, proper exception hierarchy). The findings clustered around two themes: (1) cipher exception semantics — both the encrypt-failure gap and the decrypt-failure exception type were misclassified, leading to wrong HTTP statuses and potential silent data corruption; and (2) presentation of stored-key state to the user — masked-from-ciphertext was meaningless, and corrupt-key state had no recovery path. All HIGH and MEDIUM findings except the cache-invalidation gap (HIGH-1, deferred per user) are fixed in this pass; the deferred work is tracked in a separate plan and a comment on issue #614.
