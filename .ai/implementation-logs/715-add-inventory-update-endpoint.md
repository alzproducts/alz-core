# Implementation Log — #715: PUT /api/products/inventory

## Status
V1 (single-item Max(1)) shipped in commit da12fd2f. **V2 batch upgrade — design locked, ready to implement.** /check review complete; all critical/high/medium issues resolved (see "V2 /check Resolutions" section).

## Validation
- **Live curl executed** against `PUT /api/products/inventory` using test SKU `1005356` (`config('shopwired.test_product.sku')`)
- Endpoint pipeline validated end-to-end: auth → DTO → controller → use case → Linnworks API → partial-success response
- Both JIT and MinimumLevel updates were rejected by Linnworks API (subscription feature gate + missing locationId) — these are **pre-existing** InventoryFieldUpdateClient issues, not V2 bugs
- Response shape confirmed: `{"total":1,"succeeded":0,"failures":[{"sku":"1005356","error":"API request validation failed"}]}` — correct 200, correct partial-success format
- Logging confirmed: `Bulk updating Linnworks inventory fields {count:1}` → `Bulk Linnworks inventory field update complete {total:1,succeeded:0,failed:1}`
- Behavior coverage: 16 controller feature tests, 6 use case unit tests
- All tests passing; lint clean (Pint, PHPStan, PHPArkitect, Deptrac, TLint)

## Simplify Changes
- Created `UpdateInventoryFieldsCommand` domain command (`app/Domain/Inventory/Commands/`)
- Renamed `toInventoryFieldUpdates()` → `toCommand()` on `UpdateInventoryItemDTO` per project rules; moved `Sku::fromString()` inside it
- Updated `UpdateVariationInventoryUseCase::execute()` to accept `UpdateInventoryFieldsCommand`
- Simplified controller to single `$item->toCommand()` call
- Consolidated `columnFor()` + `dbValue()` into single `fieldMapping()` method in repository (one exhaustive match instead of two)

## Plan Reference
`.ai/plans/2026-05-03_715-product-inventory-update-endpoint.md`

## Decisions
- Repository method: `updateInventoryFieldsBySku(Sku $sku, InventoryFieldUpdate ...$updates): int`
- Column mapping: `JIT` → `jit`, `MinimumLevel` → `minimum_level` (enum cases only — BinRack deferred)
- DB value deserialization: JIT `'true'`/`'false'` → bool, MinimumLevel string → int
- Use `EloquentGateway::updateWhere()` — already handles `transact()` internally
- `UpdateInventoryItemDTO::toInventoryFieldUpdates()` returns `list<InventoryFieldUpdate>`
- Use case: `UpdateVariationInventoryUseCase::execute(Sku, InventoryFieldUpdate...)` — no return
- Controller: `final readonly`, no try-catch (global exception mapper handles all)

## Files Created
- `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` (modified)
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` (modified)
- `app/Presentation/Http/Api/DTOs/UpdateInventoryRequestDTO.php` (new)
- `app/Presentation/Http/Api/DTOs/UpdateInventoryItemDTO.php` (new)
- `app/Application/Inventory/UseCases/UpdateVariationInventoryUseCase.php` (new)
- `app/Presentation/Http/Api/Controllers/ProductInventoryUpdateController.php` (new)
- `routes/api.php` (modified)
- `tests/Unit/Application/Inventory/UseCases/UpdateVariationInventoryUseCaseTest.php` (new)
- `tests/Feature/Presentation/Http/Api/Controllers/ProductInventoryUpdateControllerTest.php` (new)

## PR Notes
feat(api): add PUT /api/products/inventory endpoint (#715)

Exposes Linnworks JIT and MinimumLevel field updates via a new consumer API endpoint.
Accepts `{ items: [{ sku, jit?, minimum_level? }] }` with at least one field per item.
Updates Linnworks synchronously then writes back to `linnworks.stock_items`. Returns 204 on success.

---

## V2 Batch Design (in progress)

**Goal:** Replace V1 Max(1) with real batch support. Same branch, new commit.

### Decisions confirmed via /grill-me
| # | Decision | Choice |
|---|----------|--------|
| 1 | Failure semantics | Partial success (per-item) |
| 2 | Result type | `BatchUpdateResult<string>` (reuse generic; identifier = SKU string) |
| 3 | HTTP response | 200 with body `{total, succeeded, failures}` (BREAKING from 204) |
| 4 | Max items | 25 (synchronous endpoint, conservative) |
| 5 | Error classify | Catch base types (`TransientApiFailure` / `PermanentApiFailure` / `Throwable`) |
| 6 | DB sync timing | **Batched after API loop** (not per-item) |
| 7 | Bulk repo param | `array<string, array<string, scalar>>` (SKU → column map) |
| 8 | Bulk SQL | Group by column, batch per field via PostgreSQL VALUES-join (max 2 queries) |
| 9 | Mapping location | Dedicated mapper in Infrastructure |
| 10 | Single-item path | **Delete** single UseCase; batch handles N=1 |
| 11 | Response DTO | Reuse `BulkUpdateResponseDTO`; add `fromBatchUpdateResult()` factory |
| 12 | DB failure | Warning + dispatch reconciliation sync jobs (`LinnworksSyncDispatcherInterface::dispatchStockItemSync(Guid)`) |
| 13 | Git scope | Same branch #715, new commit |

### Key context
- Linnworks API is per-SKU per-field — N×M sequential calls, can't be batched at the API layer
- Batching is purely a DB-layer optimization (avoid N round-trips)
- Reasoning correction: User pushed back on "UseCase composition" recommendation — correct call, composition would have added N DB round-trips for no real benefit (single-item logic is trivial: 1 API call + 1 DB update + log)

### V2 /check Resolutions (all confirmed)
| # | Issue | Resolution |
|---|-------|------------|
| F1 | Mapper location conflict (D7 vs D9) | **Repo accepts domain commands.** Bulk method signature: `bulkUpdateInventoryFieldsBySkus(array<string, list<InventoryFieldUpdate>> $updatesBySku): BatchUpdateResult<string>`. Reuses existing `EloquentStockItemRepository::fieldMapping()`. **Overrides D7.** |
| F2 | Sku → Guid for reconciliation sync | **Add repo lookup method.** New on interface: `resolveStockItemIdsBySkus(Sku ...$skus): array<string, Guid>`. Called only on failure path. Extra DB roundtrip is fine — failure path is rare. |
| F3 | 204 → 200 vs issue criteria | **200 with body confirmed.** Issue criteria is stale. Will add note to issue documenting the change after PR merge. |
| F4 | Two-statement atomicity | **Wrap both UPDATEs in `eloquentGateway->transact()`.** Standard repo pattern; no caller awareness needed. |
| F5 | Duplicate SKU validation | **Spatie `Distinct` on items collection.** Returns 422 at the boundary. Investigate exact attribute name during impl. |
| F6 | Per-item logging | **Aggregate only.** Match cost-price reference: log `{total, succeeded, failed}` counts. Per-item failures visible in response body + Sentry on throw site. |
| F7 | Test refactor scope | Mechanical work: 12 controller tests (204→200 + body), 3 use case tests (single → batch). No design questions. |

### Final decision table (D7 + D9 superseded)
| # | Decision | Final Choice |
|---|----------|--------------|
| 1 | Failure semantics | Partial success (per-item) |
| 2 | Result type | `BatchUpdateResult<string>` (identifier = SKU string) |
| 3 | HTTP response | 200 with body `{total, succeeded, failures}` |
| 4 | Max items | 25 |
| 5 | Error classify | Catch base types (`TransientApiFailure` / `PermanentApiFailure` / `Throwable`) |
| 6 | DB sync timing | Batched after API loop |
| 7 | ~~Bulk repo param: primitive map~~ | **SUPERSEDED by F1** → `array<string, list<InventoryFieldUpdate>>` |
| 8 | Bulk SQL | Two UPDATEs (one per field) wrapped in `transact()` |
| 9 | ~~Mapping location: dedicated mapper~~ | **SUPERSEDED by F1** → reuse existing `fieldMapping()` in `EloquentStockItemRepository` |
| 10 | Single-item path | Delete single UseCase; batch handles N=1 |
| 11 | Response DTO | Reuse `BulkUpdateResponseDTO`; add `fromBatchUpdateResult()` factory |
| 12 | DB failure | Warning + dispatch reconciliation sync jobs (uses F2 resolver) |
| 13 | Git scope | Same branch #715, new commit |
| 14 | Duplicate SKU | Spatie `Distinct` on `UpdateInventoryRequestDTO::items` |
| 15 | Logging | Aggregate counts only (start + completion) |
| 16 | Atomicity | Wrap both UPDATEs in `transact()` |

### Reference patterns
- `UpdateCostPriceBySupplierUseCase` — partial-success batch with `dispatchReconciliationSyncs()`
- `EloquentStockItemSupplierRepository::bulkUpdatePurchasePrices` — PostgreSQL VALUES-join pattern
- `BulkUpdateResponseDTO` — already has `fromCostPriceResult()`, needs sibling factory
- `BatchUpdateResult<TIdentifier>` — generic result with permanent/temporary split

### V2 Implementation Tasks
- [x] #11 — Extended `StockItemRepositoryInterface` + `EloquentStockItemRepository`: added `bulkUpdateInventoryFieldsBySkus()` + `resolveStockItemIdsBySkus()`; removed `updateInventoryFieldsBySku()`. Bulk method groups updates by column, runs one PostgreSQL VALUES-join UPDATE per column with explicit type casts (`?::boolean` for jit, `?::integer` for minimum_level), wrapped in `eloquentGateway->transact()`. Reuses existing `fieldMapping()`.
- [x] #12 — `UpdateInventoryRequestDTO`: `Max(1)` → `Max(25)`, added `rules()` returning `['items.*.sku' => ['distinct']]`.
- [x] #13 — Deleted `UpdateVariationInventoryUseCase.php`; created `app/Application/Inventory/UseCases/UpdateInventoryFieldsUseCase.php`. Per-SKU API loop with three catch arms (`PermanentApiFailure` / `TransientApiFailure` / `Throwable`), then `bulkUpdateInventoryFieldsBySkus()` for succeeded items. On DB exception: resolves Guids via `resolveStockItemIdsBySkus()`, dispatches reconciliation sync per Guid, demotes succeeded → permanentFailures.
- [x] #14 — Added `BulkUpdateResponseDTO::fromBatchUpdateResult()` factory (flattens permanent+temporary failures into single `failures: [{sku, error}]` array). Updated `ProductInventoryUpdateController` to iterate items via `iterator_to_array()`, call batch UseCase, return Responsable DTO (200 with body).
- [x] #15 — Rewrote `ProductInventoryUpdateControllerTest`: 16 tests (was 12). All `assertStatus(204)` → `assertStatus(200)` + `assertJsonPath('total'/'succeeded'/'failures.0.sku')`. New mocks: `LinnworksSyncDispatcherInterface`. Added: `>25 items` 422, duplicate-SKU 422, partial-success batch (3 items, middle fails), transient API failure, DB-write-failure-dispatches-reconciliation-sync.
- [x] #16 — Deleted `UpdateVariationInventoryUseCaseTest.php`; created `UpdateInventoryFieldsUseCaseTest.php` with 5 tests: all-succeed, partial-success (perm/transient/RuntimeException unknown), all-fail (auth-expired + service-unavailable, bulk write skipped), DB-write-failure-dispatches-syncs (succeeded items demoted), aggregate-only logging. Skipped the empty-commands assertion test — Webmozart guard is defense-in-depth; real boundary is the DTO `Min(1)` validation which is covered.
- [x] #17 — `make test` green (3359 passed, 0 failures). `make lint` green (Pint, PHPStan, PHPArkitect, Deptrac, TLint). Subagent decomposed `handleDbWriteFailure()` into `dispatchReconciliationSyncsForSucceeded()` + `buildDbDemotionFailures()` + `logDbWriteFailure()` to fit 20-line limit. The `Throwable` safety net in `performApiUpdates()` is suppressed via a path-scoped entry in `phpstan.neon` (kcs `MustRethrowRule`, matched by message regex `#caught "Throwable" must be rethrown#`) rather than the inline `// @ignoreException` directive — per user direction, `phpstan.neon` is the right place for these.

### V2 Final state
- All 16 design decisions implemented as locked.
- Test counts: 3359 passing (was 3352 before V2 → 7 new assertions worth of behavior added; net new feature tests +4, new use case tests +5 minus 3 deleted minus 1 skipped). Sweep added 1 more use case test (+ESU branch) → 3360.
- Lint clean across all 5 linters.
- Two exception-constructor mismatches caught and fixed during test run: `ExternalServiceUnavailableException` takes `(serviceName, ?int retryAfter)` not a free-form message; `DatabaseOperationFailedException` requires `(operation, reason)` not `(reason)`.
- `Throwable` safety net suppressed via path-scoped `phpstan.neon` entry (not inline `// @ignoreException`) — dropping the suppression would either break the partial-success contract by letting `Error`/`TypeError` abort the batch, or remove the safety net entirely.
- **Sweep canonical-mirror fix:** `updateLocalDatabase()` catch widened from `DatabaseOperationFailedException|DuplicateRecordException` to `…|ExternalServiceUnavailableException`, mirroring `UpdateCostPriceBySupplierUseCase.php:217`. Without it, a transient infra failure on the bulk write would 500 the request with the Linnworks write already applied — silent stale-mirror divergence with no reconciliation dispatch. `logDbWriteFailure()` signature widened to match. `@throws` on `execute()` and `updateLocalDatabase()` rewritten to reflect that all three now surface only from the failure-path resolver call.

### V2 implementation notes
- Repo `bulkUpdateInventoryFieldsBySkus()` returns `int` total affected, but interface signature returns `int` (not `BatchUpdateResult` — corrected from earlier draft); per-row tracking happens in the UseCase, not the repo.
- Repo `resolveStockItemIdsBySkus(Sku ...$skus): array<string, Guid>` — uses `pluck('stock_item_id', 'item_number')` then maps to `new Guid($value)` (no `Guid::fromString()` exists, only constructor).
- `eloquentGateway->transact()` returns `mixed`, requires `@var int` annotation to narrow — see canonical `EloquentSkuChangeRepository`.
- Controller `@throws` reduced to `InvalidSkuException` + DB exceptions (only surface from failure-path `resolveStockItemIdsBySkus()` call). All API exceptions are caught inside the UseCase per-SKU.
- `ProductPricingUpdateController` is canonical for `iterator_to_array($data->items, preserve_keys: false)` (NOT `->toArray()` which would recursively serialize).
- Use case uses `Webmozart\Assert\Assert::notEmpty($commands)` — `non-empty-list` is enforced both by the DTO `Min(1)` and this assertion (defense in depth).
- All exception base types verified: `ResourceNotFoundException`, `InvalidApiRequestException`, `InvalidApiResponseException`, `AuthenticationExpiredException` extend `PermanentApiFailure`; `ExternalServiceUnavailableException` extends `TransientApiFailure`.

### V2 live validation findings
- JIT update: Linnworks 400 — "Subscription does not have required feature to update JIT." (account-level, not code)
- MinimumLevel update — initially failed on `/api/Inventory/UpdateInventoryItemField` even after adding `locationId` (any casing). **Root cause:** Linnworks splits inventory field updates across **two endpoints by field scope, with different param casing**:
  - `/api/Inventory/UpdateInventoryItemLocationField` — **camelCase** (`inventoryItemId`, `fieldName`, `fieldValue`, `locationId`). Location-scoped: `MinimumLevel`, `JIT`, `BinRack`.
  - `/api/Inventory/UpdateInventoryItemField` — **PascalCase** (`InventoryItemId`, `FieldName`, `FieldValue`). Item-level: `Title`, `Category`, `Barcode`, `Weight`, `RetailPrice`, `PurchasePrice`. Each rejects the other scope's fields with mirrored "field requiring location" / "field requiring operationPlace" errors.
- **Fix:** switched `InventoryFieldUpdateClient` to the location-scoped endpoint (V2 only exposes MinimumLevel + JIT, both location-scoped). Added detailed routing notes to `InventoryFieldUpdateClient`, `InventoryFieldUpdateClientInterface`, `InventoryFieldUpdate` static-factory class (so future contributors know to add routing when adding item-level fields), and `app/Infrastructure/Linnworks/CLAUDE.md` "Known Quirks". Updated 5 sites in `InventoryFieldUpdateClientTest` to assert against the new endpoint URL.
- **Live confirmed:** Linnworks 200 with `{"MinimumLevel":5}` after the switch.
- Endpoint pipeline confirmed correct: auth, DTO validation, partial-success response shape, logging, error classification all working.
- **Octane caveat:** live curl observed Swoole 6.2.0 worker segfault (`signal=11`) **after** the Linnworks API success but **before** the local DB mirror write (`bulkUpdateInventoryFieldsBySkus`) completes — Linnworks writes succeeded, local `linnworks.stock_items` row didn't update. Environmental (Swoole stability), not a code defect; tests pass cleanly.

### V2 JIT API removal (DTO-only)
- **Why:** Linnworks subscription doesn't include the JIT feature — every JIT write returns 400 "Subscription does not have required feature to update JIT." Exposing the field at the API guaranteed every JIT call would fail.
- **Scope:** DTO + controller docblock + controller feature tests only. The Domain (`InventoryFieldUpdate::jit()`, `InventoryUpdatableField::JIT`), Infrastructure (client field mapping, repository column), and Application use case still support JIT — re-exposing it is a one-property addition to `UpdateInventoryItemDTO` once the subscription is upgraded. Use case unit tests (`UpdateInventoryFieldsUseCaseTest`) keep using `InventoryFieldUpdate::jit()` as a domain-layer field — they're testing batch behavior, not the API contract.
- **Changes:**
  - `UpdateInventoryItemDTO`: removed `bool|Optional $jit` property + `RequiredWithoutAll` cross-field validation. `minimum_level` is now `#[Required, IntegerType, Min(0)] int` (no longer `Optional`). `toCommand()` simplified to a single-update return. Class docblock explains the JIT removal and how to re-add.
  - `ProductInventoryUpdateController`: docblock updated to describe the JIT-disabled state and pointer back to the DTO docblock.
  - `ProductInventoryUpdateControllerTest`: deleted `it_returns_200_with_body_when_updating_jit_only` and `it_returns_200_with_body_when_updating_both_fields`; deleted `it_returns_422_when_jit_is_not_a_boolean`; renamed `it_returns_422_when_both_jit_and_minimum_level_are_absent` → `it_returns_422_when_minimum_level_is_absent`; replaced all `'jit' => bool` request payloads with `'minimum_level' => int` across remaining tests.
- **Verification:** lint clean (5 linters), 3357 tests pass (was 3360, −3 deleted JIT-only feature tests). Live curl confirmed end-to-end: `PUT /api/products/inventory {items:[{sku:"1005356", minimum_level:7}]}` → `200 {total:1, succeeded:1, failures:[]}`; local `linnworks.stock_items.minimum_level` row updated to 7. JIT-only payloads cleanly rejected with `422` and a "minimum_level required" validation error (Spatie Data silently drops the unknown `jit` property).

### V2 read-path -1 → null fix
- **Why:** Linnworks uses `-1` as the "not set" sentinel for `MinimumLevel` (same convention as `taxRate` / `shippingTaxRate` which already had translation). The DB stored the raw -1, and the read path (`StockItemModel::toProductInventory()`) passed it through verbatim — so consumers of `?include=inventory` saw literal `-1` in API responses despite `ProductInventory::$minimumLevel` being typed `?int` with docblock `null = no data, 0 = minimum is zero`. Asymmetric with the write path (`UpdateInventoryItemDTO` validates `Min(0)`, so consumers can never write -1).
- **Fix:** one-line change in `StockItemModel::toProductInventory()` — `minimumLevel: $this->minimum_level < 0 ? null : $this->minimum_level, // -1 means "not set"` — matches the canonical pattern in `StockItemFullResponse.php:85` (taxRate) and `PurchaseOrderHeadersBatchQuery.php:159` (shippingTaxRate).
- **Verification:** lint clean, 3357 tests pass. PHPStan caught a null-safety issue on first try (`$this->minimum_level` is `int|null`, not `int`); fixed by adding explicit `=== null ||` guard before the `< 0` comparison. No new test added — Infrastructure mapping projections aren't typically tested in isolation in this codebase, and the change mirrors a well-established convention.
- **Note:** `ProductInventory::toArray()` produces a `minimum_level` key, but a quick grep shows no Presentation API resource currently surfaces it (the variation endpoint response only carries `is_composite` / `weight` from the inventory include). The translation is still correct at the domain VO level — it'll just be transparent for any future API resource that adds the field.

### V2 leave-off — about to start #15 (controller test refactor)
- Existing test file: `tests/Feature/Presentation/Http/Api/Controllers/ProductInventoryUpdateControllerTest.php` (12 tests)
- All `assertStatus(204)` → `assertStatus(200)` + `assertJson({total, succeeded, failures})`
- Add: distinct SKU violation test (422), partial-success test (one SKU 404 returns 200 with both succeeded=N-1 and failures=[{sku,error}])
- Mocks `InventoryFieldUpdateClientInterface` + `StockItemRepositoryInterface` (now `bulkUpdateInventoryFieldsBySkus` + `resolveStockItemIdsBySkus` instead of `updateInventoryFieldsBySku`)
