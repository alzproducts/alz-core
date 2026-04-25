# Implementation Log: Custom Field Settings Write API

**GitHub Issue**: #634
**Plan Document**: `.ai/plans/2026-04-24_634-custom-field-settings-write-api.md`
**Status**: In Progress
**Started**: 2026-04-24
**Completed**: —

## Overview

Adds the consumer-facing write API for local custom field settings (general + product). Builds on Phase 1 (#611) which delivered the `catalog.custom_field_general_settings` / `catalog.custom_field_product_settings` tables and the `ConfiguredFieldDefinition` composition wrapper.

## Decision Log

### 2026-04-24
- **Decision**: Use JSON Merge Patch semantics via Spatie LaravelData `Optional` properties — no separate FormRequest layer.
- **Why**: Plan locked; matches `feedback_spatie_data_over_formrequests` preference for simple internal endpoints.

- **Decision**: Two new repos extend `AbstractEloquentRepository` with `getUpsertKeys()` returning `['custom_field_definition_id']`.
- **Why**: First-write creates row, subsequent writes update; handled by existing upsert infrastructure.

- **Decision**: Both write endpoints return the full enriched `ConfiguredFieldDefinition`.
- **Why**: Frontend can replace its local cache entry in one round-trip.

- **Decision (revised 2026-04-24)**: HTTP verb is **PUT with partial-update semantics**, not PATCH.
- **Why**: Original plan specified PATCH (spec-correct for JSON Merge Patch). After implementation, noticed existing consumer API (`PUT /products/{id}`, `/categories/{id}`, `/brands/{id}`) already uses PUT with partial-update semantics — the de-facto house style. Internal consistency outweighs spec orthodoxy for a frontend-consumed internal API. Body semantics (absent = unchanged, null = clear, value = set) are unchanged; only the verb differs.

- **Decision**: `ProductSettingsNotApplicableException` is a new domain exception, translated to HTTP 422 with `code: product_settings_not_applicable` in presentation.
- **Why**: Attempting to write product settings to a non-product definition is a business-rule violation, not an infrastructure error.

## Simplify pass (2026-04-24)

Three parallel review agents converged on: (1) stringly-typed `'catalog'`/`'custom_field_definition'` repeated across throws, (2) duplicated `resolveInternalId`/`reloadDefinition` helpers across both save use cases, (3) redundant DB round-trip in the product-settings write path (full enriched load for item_type check + a second lightweight query for the same row's UUID).

**Applied:**
- New `app/Application/Catalog/Resolvers/CustomFieldDefinitionResolver.php` centralises the 404 handling and the lookups. Holds the `SERVICE_NAME` / `RESOURCE_TYPE` constants in one place.
- New `app/Application/Catalog/Resolvers/CustomFieldResolutionResult.php` — Application-layer tuple pairing internal UUID + `ConfiguredFieldDefinition` (naming suffix `Result` chosen to satisfy PHPArkitect's Application-class rule).
- New repo method `CustomFieldRepositoryInterface::findEnrichedWithInternalId()` returns both UUID and enriched VO in a single query. Eliminates the product-settings redundant lightweight lookup.
- `GetConfiguredFieldDefinitionUseCase` now a 3-line delegator to `resolver->reload()`.
- Both save use cases inject the resolver; private helper methods removed.
- `SaveCustomFieldProductSettingsUseCase` uses `resolveEnriched()` for a single-trip item_type check + UUID extraction.

**Deliberately skipped (nits):**
- `admin_only` default in `ConfiguredFieldDefinitionResource` (plan explicitly says DB default handles it).
- `toChangeSet()` three-part split grouping (reader-clarity nit, not behavioural).
- `@throws DuplicateRecordException` on read methods (pre-existing noise, not introduced by this PR).
- List pagination (admin endpoint, definitions stable at <100 rows).

## Sweep pass (2026-04-24)

- **Missing logging.** Sweep agent caught that the two read use cases (`GetConfiguredFieldDefinitionUseCase`, `ListConfiguredFieldDefinitionsUseCase`) lacked the `LoggerInterface` entry/exit `info` logs mandated by `app/Application/CLAUDE.md` and modelled by `GetBrandUseCase`/`ListBrandsUseCase`. Added. Not strictly a bug — pre-existing patterns vary — but aligned with convention.
- **Tests added (21 cases):**
  - `tests/Unit/Application/Catalog/Resolvers/CustomFieldDefinitionResolverTest.php` — null-to-404 branching on all 3 methods, asserting `serviceName`/`resourceType`/`resourceId` on the thrown exception.
  - `tests/Unit/Presentation/Http/Api/DTOs/UpdateCustomFieldGeneralSettingsRequestDTOTest.php` + product DTO test — merge-patch semantics (absent vs null vs value).
  - `tests/Unit/Presentation/Http/Api/Resources/ConfiguredFieldDefinitionResourceTest.php` — `admin_only ?? false` fallback + product-block nullification.
- **Verified clean:** no PHPStan suppressions, no new complexity-baseline entries, no CA violations, no oversized use cases.
- **Out of scope** (flagged, not written): unit tests for the two save use cases' merge-patch helper methods — branching is mechanical (absent / null / value) and each helper is 5 lines. Followup candidate if mutation testing thresholds on Application layer prove tight.

## Review pass (2026-04-24)

User review flagged vestigial nullable returns on three repo read methods. Traced through the callers: all three (`findByExternalId`, `findInternalIdByExternalId`, `findEnrichedWithInternalId`) were only called by `CustomFieldDefinitionResolver`, which immediately converted null → throw.

**Initially applied (then partially reverted — see next section):**
- `CustomFieldRepositoryInterface` read methods now non-nullable; declare `@throws RecordNotFoundException`.
- `EloquentCustomFieldRepository` migrates the two eager-loaded reads to `EloquentGateway::findOrFail()` with a mapper closure; column-projection method keeps the custom `->value('id')` query and throws `RecordNotFoundException` explicitly when null.
- Added `RESOURCE_TYPE = 'custom_field_definition'` constant on the repo (was stringly repeated in three places).

## Resolver removal (2026-04-24)

Second review pass noticed `InternalApiExceptionMapper` already maps `RecordNotFoundException` → HTTP 404 (explicit match arm before the `TransientApiFailure` → 503 arm; see mapper comment). That made the resolver's catch-and-rethrow semantic translation redundant, and with the translation gone, the resolver's three methods collapsed to pure rename passthroughs.

**Applied:**
- Deleted `app/Application/Catalog/Resolvers/CustomFieldDefinitionResolver.php` and its unit test.
- Moved `CustomFieldResolutionResult.php` from `Application/Catalog/Resolvers/` to `Application/Catalog/Results/` (matches existing `*Result` convention). Removed the empty `Resolvers/` dirs.
- `GetConfiguredFieldDefinitionUseCase`, `SaveCustomFieldGeneralSettingsUseCase`, `SaveCustomFieldProductSettingsUseCase` now inject `CustomFieldRepositoryInterface` directly.
- Propagated the `@throws` change (`ResourceNotFoundException` → `RecordNotFoundException`) up through the three custom-field controllers. HTTP outcome unchanged — mapper handles both as 404.

**Surfaced (follow-up):** `EloquentGateway::findOrFail()` throws `RecordNotFoundException` (transient) by default. For HTTP consumers this is fine because `InternalApiExceptionMapper` translates it to 404. For non-HTTP callers (jobs, console commands, internal code paths) the "transient by default" semantic is still the wrong default for point-lookups of user-supplied IDs. Narrowed scope tracked in #640.

## Partial-update refactor (2026-04-24)

Fourth review pass pushed back on `mergeChangeSet` in both save use cases. Diagnosis: the helper wasn't doing validation (DTO `#[Enum]` + `#[Max]` already handled that); it existed purely because the settings repo's `save()` demanded a full-entity VO, forcing a load → merge → save dance in memory for every write. Two other review items (`CustomField*SettingsRepositoryInterface::findByDefinitionId` returning nullable) traced back to the same root cause — both methods only existed to feed the in-memory merge.

Surveyed other consumer-API PUT flows in this codebase:
- Products / brands / categories: `array<string, mixed>` → typed `*FieldUpdate` VO list → **external** ShopWired API (no merge needed; external API handles partials natively).
- Pricing / cost-price: controller builds typed `UpdatePriceCommand` / `UpdateCostPriceCommand` from DTO → consumed downstream.
- This PR (pre-refactor): `array<string, mixed>` → in-memory merge → full-entity save. New shape with no prior art.

Aligned with the Command pattern (shape B above).

**Applied:**
- New `App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand` / `SaveCustomFieldProductSettingsCommand` — nullable typed fields + `public readonly array<string> $touchedKeys` naming the DB columns present in the request.
- DTOs expose `toCommand()` (replaces `toChangeSet(): array`). Enum `::from()` conversion lives here, not in the use case — DTO `#[Enum]` already validated upstream, so `from()` cannot throw in practice. Factory lives on the DTO because Command (Application) must not depend on DTO (Presentation).
- Settings repo interfaces: `save(Uuid, Command): void`. `findByDefinitionId` deleted (no longer needed — partial upsert is a single DB round-trip).
- Settings repos: `attributes = ['custom_field_definition_id' => $uuid->value, ...touchedAttributes($command)]` passed to `EloquentGateway::upsertOne`. `computeUpdateColumns` derives the update-column list from the prepared row, so only touched columns are written on conflict; untouched columns keep their previous value (update) or the DB default (first-create).
- Use cases collapse to ~15 lines each: log → resolve internal id → `repo->save($uuid, $command)` → return enriched. `mergeChangeSet`, `resolveNullable`, `resolveSelectType`, `resolveValidationRule`, `resolveBool`, `resolveNullableBool`, `resolveStockItemUpdateMode` all deleted.
- Controllers drop the `@throws TypeError` / `@throws ValueError` noise — enum conversion can't throw after the DTO validates.
- Dropped `CustomFieldGeneralSettingsModel::fromDomainAttributes` + `CustomFieldProductSettingsModel::fromDomainAttributes` (only called by the old save path and by tests). Both models now implement `DomainConvertibleInterface` directly instead of `EloquentDomainMappableInterface<T>`. Corresponding `from_domain_attributes_*` test cases removed.

## New `Uuid` value object (2026-04-24)

Review flagged that the settings-row FK was being passed around as a plain `string` throughout this PR. Rather than keep the primitive obsession, introduced a new Domain VO:

- `App\Domain\ValueObjects\Uuid` — wraps `gen_random_uuid()`-style UUIDs generated by our own Postgres schemas (`catalog.*`, `shopwired.*`, etc). Mirrors `Guid` exactly (constructor validates via `Assert::uuid`, `::fromTrusted()` factory, `equals()`).
- Separate from `Guid` on purpose: `Guid` models external-system IDs that happen to be UUIDs (Linnworks stockItemId, Supabase); `Uuid` models identifiers we own. Keeping the two types distinct prevents accidental substitution at type-check time.
- Applied on: `CustomFieldRepositoryInterface::findInternalIdByExternalId(int): Uuid`, `CustomFieldResolutionResult::internalId: Uuid`, `CustomFieldGeneralSettingsRepositoryInterface::save(Uuid, Command)`, `CustomFieldProductSettingsRepositoryInterface::save(Uuid, Command)`.
- Repo implementations extract the raw string at the DB boundary via `$uuid->value`.
- Unit test mirrors `GuidTest` exactly (construction, uppercase, invalid, empty, `fromTrusted`, `equals` x3).

## Lint pass after refactor (2026-04-24)

Stop hook surfaced 9 PHPStan errors after the partial-update refactor. All addressed:

- **`alz.shopwiredModelMustImplementMappable` (2× on settings models)** — custom rule (`app/DevTools/PhpStan/Rules/Infrastructure/ShopwiredModelMustImplementMappableRule.php`) previously required every Shopwired/Catalog model to implement `EloquentDomainMappableInterface` (bidirectional `toDomain()` + `fromDomainAttributes()`). The two settings models now legitimately have no Domain→Model path — the save side takes an Application Command, not a Domain VO. **Rule refined** to accept either `EloquentDomainMappableInterface` (stronger, still required for `AbstractEloquentRepository` consumers) OR `DomainConvertibleInterface` (weaker, read-only). `EloquentDomainMappableInterface extends DomainConvertibleInterface`, so every existing compliant model still passes. User approved via `AskUserQuestion`.
- **`alz.excessiveMethodLength` (1× on `toCommand()` in General DTO)** — decomposed into five typed private static helpers (`resolveString`, `resolveNullableBool`, `resolveBool`, `resolveSelectType`, `resolveValidationRule`). Each returns `[value, touchedKeys]` tuple. `toCommand()` now ~12 lines of pipeline; helpers are 7-12 lines each.
- **`missingType.checkedException` (6× on enum `::from()` calls)** — `TypeError` / `ValueError` from `CustomFieldValueSelectType::from`, `CustomFieldValidationRule::from`, `StockItemUpdateMode::from`. Upstream `#[Enum(…)]` Spatie Data validator already rejects bad values, so these can't actually throw. Path-based ignore added to `phpstan.neon` for both DTO files, mirroring the existing precedent at `phpstan.neon:362-367` (`ListProductsRequestDTO` — same situation, same fix). User approved path-based over inline `@phpstan-ignore`.

## Deviations from Plan

- **URL path uses external int id, not UUID.** Plan sample shows `"id": "uuid"` in JSON response, but Phase 1 `CustomFieldDefinition` VO only carries `int $id` (ShopWired external_id). URL path `{definitionId}` is the external int id — matches existing consumer API (`{productId}` pattern). Response `id` field == external int id.

- **`ConfiguredFieldDefinition` NOT modified.** Originally considered adding a UUID property but it rippled across ~15 Phase 1 test files constructing the VO. Instead, the existing `CustomFieldRepositoryInterface` gets a lightweight `findInternalIdByExternalId(int): Uuid` method for write use cases to resolve the FK (originally `?string`, narrowed to non-nullable during the review pass, then typed as `Uuid` during the partial-update refactor). Read use cases remain untouched.

- **Settings repo interfaces do NOT extend `RepositoryWriteInterface`.** Plan says they do, but `save(object $entity)` expects an entity carrying its own identity — our settings VOs don't carry the FK. Post-refactor the shape is `save(Uuid $definitionInternalId, SaveCustomField*SettingsCommand $command): void` — a typed-Command partial upsert, single DB round-trip. Implementations inject `EloquentGateway` directly (not `AbstractEloquentRepository`) since there's no Entity → Attributes contract that makes sense.

- **New interfaces bound in `CatalogServiceProvider`.** Plan mentions "catalog/shopwired"; since these repos write to `catalog.*` tables (not `shopwired.*`), `CatalogServiceProvider` is the correct owner.

- **`ProductSettingsNotApplicableException` placed in `app/Domain/Catalog/CustomFields/Exceptions/`** alongside `CustomFieldNotFoundException` (not generic `app/Domain/Exceptions/`). Matches existing locality convention.

## Blockers / Open Questions

- [x] **Lint (2026-04-24)** — All 12 initial lint errors + 8 cascading errors fixed. `alz.excessiveMethodLength` on `execute()` methods resolved by extracting `resolveInternalId()`, `reloadDefinition()`, `assertProductFieldExists()` helpers. `nullsafe.neverNull` resolved via explicit `$x === null ? default : $x->prop` pattern. `missingType.checkedException` for enum `::from()` resolved by adding `@throws TypeError` + `@throws ValueError` up the call chain (DTO `#[Enum]` attribute validates upstream, but PHPStan doesn't know that).

- [x] **`InternalApiExceptionMapper::statusCode()`** decomposed into `domainStatusCode()` + `frameworkStatusCode()` with null-fallthrough to 500. The old single-match was pushed from 20 to 21 lines by adding `ProductSettingsNotApplicableException`.

## Technical Notes

- Migration column name is `stock_item_update_mode` (not `update_linnworks_stock_item` — trust migration).
- DB default handles `admin_only = false` on first-create when absent from body.
- `general` block always present in response (`CustomFieldGeneralSettings::defaults()` when no row exists); `product` is null when `item_type ≠ 'product'` or no row exists yet.

## PR Notes

### What
Adds consumer-facing API to list/read custom field definitions enriched with local settings, and two PATCH endpoints to upsert general/product settings using JSON Merge Patch semantics.

### Why
Phase 1 (#611) delivered the storage and read-side composition wrapper. Frontend needs HTTP endpoints before it can configure local custom field settings.

### Key Decisions
- One controller per resource (3 total); invokable PUT controllers for settings resources.
- Spatie LaravelData DTOs with `Optional` fields for merge-patch semantics — no separate FormRequest.
- Full enriched definition returned from PUT endpoints for single-round-trip cache replacement.
- Typed Command VOs + `touchedKeys` array at the Application → Infrastructure boundary. Repos do partial upsert on only the touched columns; DB defaults cover untouched columns on first-create. Eliminates load-merge-save — a single DB round-trip per write.
- New `App\Domain\ValueObjects\Uuid` domain type for our own schema UUIDs (distinct from `Guid`, which models external-system UUIDs).

### Testing
- Existing tests (Phase 1 read path) remain green.
- (Feature tests per plan verification — see Section Verification in plan.)

## UUID-keyed write paths (2026-04-24, post-sweep refactor)

**Architectural shift**: settings write endpoints now identify the resource by the catalog-schema UUID, not the ShopWired external int id. Rationale: the UUID is our canonical identifier for settings rows (the tables FK it). The ShopWired external id is canonical for *upstream-owned* entities (Product, Brand) but not for our own catalog rows. The frontend always fetches the enriched definition before editing settings, so it has the UUID. Decision: expose internal_id on ConfiguredFieldDefinitionResource; routes switch to `{definitionUuid}` with `whereUuid()`.

### Scope of refactor
Pre-sweep state was already committed: commit `0ea02796` (`feat(catalog): add write API for custom field general and product settings (#634)`). Current branch state is that commit plus in-progress edits.

**Completed:**
- `ConfiguredFieldDefinition` VO — added `public Uuid $internalId` as first constructor arg (before `$base`). Edit already applied to `app/Domain/Catalog/CustomFields/ValueObjects/ConfiguredFieldDefinition.php`.

**Next (in order):**
1. `CustomFieldDefinitionModel::toConfiguredDomain()` — pass `Uuid::fromTrusted($this->id)` as first arg.
2. All 28 existing `new ConfiguredFieldDefinition(...)` call sites — add `internalId:` (named) or prepend UUID (positional). Mostly test fixtures, harmless UUID like `11111111-2222-3333-4444-555555555555`. Full list from `git grep -n "new ConfiguredFieldDefinition("` — includes domain VO tests (DateTime/Null/String/Toggle/etc), SaleManagement, ProductView, SaleSettings tests, plus my four new PR test files.
3. Delete `app/Application/Catalog/Results/CustomFieldResolutionResult.php` (all callers removed after next step).
4. `CustomFieldRepositoryInterface`:
   - Delete `findInternalIdByExternalId(int): Uuid`
   - Delete `findEnrichedWithInternalId(int): CustomFieldResolutionResult`
   - Add `findByInternalId(Uuid): ConfiguredFieldDefinition` (throws RecordNotFoundException)
5. `EloquentCustomFieldRepository` — delete the two old methods, add `findByInternalId` (reuses `findOrFail` on 'id' column).
6. `SaveCustomFieldGeneralSettingsUseCase::execute(Uuid $internalId, Command): ConfiguredFieldDefinition` — body: `save()` then `findByInternalId()` refresh. Drop `CustomFieldRepositoryInterface::findInternalIdByExternalId` call.
7. `SaveCustomFieldProductSettingsUseCase::execute(Uuid $internalId, Command): ConfiguredFieldDefinition` — body: `findByInternalId()` → `assertProductField()` → `save()` → `findByInternalId()` refresh. Drop `CustomFieldResolutionResult` import.
8. `GetConfiguredFieldDefinitionUseCase::execute(IntId $definitionExternalId)` — migrate to IntId (GET keeps external-id path).
9. `routes/api.php`:
   - Settings PUT routes: `{definitionUuid}` + `->whereUuid('definitionUuid')` (no `->whereNumber()`)
   - GET show: unchanged (still `whereNumber`).
10. Controllers:
    - `CustomFieldGeneralSettingsController::__invoke(string $definitionUuid, UpdateCustomFieldGeneralSettingsRequestDTO $data)` — calls `execute(new Uuid($definitionUuid), $data->toCommand())`.
    - `CustomFieldProductSettingsController` — same shape.
    - `CustomFieldDefinitionController::show(int $definitionId)` — calls `execute(IntId::from($definitionId))`.
11. `ConfiguredFieldDefinitionResource::toArray()` — add `'internal_id' => $definition->internalId->value` (additive, after `id`).
12. Tests:
    - `ConfiguredFieldDefinitionResourceTest` (5 call sites) — pass `internalId` and assert it appears.
    - `ConfiguredFieldDefinitionTest` (5 call sites) — pass `internalId`.
    - `SaveCustomFieldGeneralSettingsUseCaseTest` — rewrite: mocks `findByInternalId`, drops `findInternalIdByExternalId`.
    - `SaveCustomFieldProductSettingsUseCaseTest` — rewrite: mocks `findByInternalId` twice (guard + refresh), drops `CustomFieldResolutionResult`.
    - `CustomFieldGeneralSettingsControllerTest` + `CustomFieldProductSettingsControllerTest` — URL changes to `/catalog/custom-field-definitions/{uuid}/...`, mock `findByInternalId`, controller takes Uuid.
    - Misc VO tests (DateTime/Null/String/Toggle/etc, SaleManagement, ProductView, SaleSettings, CustomFieldMergerService, CustomFieldValueFactory, GetProductCustomFieldsUseCase, CustomFieldDefinitionModel) — pass a constant UUID to ConfiguredFieldDefinition; no semantic change.
13. `findColumnOrFail` on `EloquentGateway` — **NOT added this PR** (user originally requested it, but with `findInternalIdByExternalId` deleted the concrete caller disappeared; can revisit if another use case needs it).

### Decisions locked via AskUserQuestion this session
- GET show endpoint stays on external int id (IntId).
- UUID exposed as new `internal_id` field in the resource (non-breaking, additive).
- `ConfiguredFieldDefinition` gets UUID as first constructor arg (full ripple over wrapper approach).
- `CustomFieldResolutionResult` deleted; `findColumnOrFail` deferred.
- Items 2/3/7 (touchedAttributes placement + touchedKeys command shape) — deferred for later discussion.

### Blockers / watch-outs
- PHPStan may flag `alz.shopwiredModelMustImplementMappable` again when I touch the model (unlikely; the rule was widened previously to accept `DomainConvertibleInterface`).
- Make sure the Uuid assertion constructor doesn't choke on the UUID literal used in test fixtures (`11111111-2222-3333-4444-555555555555` is valid v4-ish format).

### Refactor complete (2026-04-24 session close)

All items 1–12 landed. `make test` + `make lint` green.

**Mockery gotcha surfaced in Feature tests**: Mockery's default `with($uuidObject)` comparator fails when the controller constructs a *fresh* `Uuid` instance from the URL string — even though the two instances are value-equal. Fixed by switching the three feature-level mocks (2× product-settings, 1× general-settings happy path) to `Mockery::on(static fn(Uuid $u) => $u->value === self::FIXTURE_UUID)`. Unit-level `SaveCustomField*UseCaseTest` continues to use `with($internalId)` directly because the same instance is passed through the use case in that scenario.

**Resource test addition**: new `exposes_internal_uuid_alongside_external_id` test pins the `internal_id` field so future edits to `ConfiguredFieldDefinitionResource::toArray()` can't silently drop it.

**Dual-identity flow for reviewers**:
- GET list / show → keyed by ShopWired external `IntId` (legacy pairing with Product list)
- PUT general-settings / product-settings → keyed by catalog `Uuid`
- Response body carries both `id` (external int) + `internal_id` (UUID) so the frontend can do a one-shot fetch-then-edit.

**Item 13 (`findColumnOrFail` on EloquentGateway)** remains out of scope; no live caller in this PR.
