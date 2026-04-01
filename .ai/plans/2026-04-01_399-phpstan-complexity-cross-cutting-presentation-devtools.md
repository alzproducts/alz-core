# Plan: Issue #399 — PHPStan Complexity Baseline: Cross-cutting Infra, Presentation & DevTools

## Context

2nd of 4 issues reducing `phpstan-complexity-baseline.neon` (469 total violations). #398 (external integrations, 91 errors) completed — established philosophy in `.ai/implementation-logs/396-399-complexity-baseline-reduction.md`. Working outside-in through Clean Architecture layers; #399 covers the outermost layers (Presentation, DevTools, Providers) plus shared infrastructure plumbing.

**Implementation approach:** `/refactor` command — zero behavioral changes, sectioned work with checkpoints, implementation log tracking.

## Decisions (Resolved)

1. **`provides()` rule-level exclusion** — **YES.** Add to `EXCLUDED_METHODS`. Same justification as mapper methods.
2. **EloquentGateway class-level** — **Permanent exclusion.** Refactor all 8 method-length violations. Param-count (6) stays in baseline.
3. **AbstractEloquentRepository class-level** — **Permanent exclusion now.** Still refactor method violations.
4. **ShopwiredServiceProvider** — lives in `app/Providers/`, correctly scoped to #399. Verify no duplicate entries at implementation time.
5. **`toArray()` in API Resources** — **Evaluate individually.** No rule-level exclusion.

## Sections

### Section 0: Rule-Level Changes (cross-cutting)
**If approved:** Add `provides` to `EXCLUDED_METHODS` in `ExcessiveMethodLengthRule.php`
- Resolves: LinnworksServiceProvider::provides (22 lines), ShopwiredServiceProvider::provides (58 lines)
- **File:** `app/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRule.php`
- **Entries resolved:** ~2-3

### Section 1: DevTools/PHPStan Rules + GitHooks (~30 entries, ~20 files)
**Risk:** Low — self-contained rules with their own tests
**Pattern:** Extract from `processNode()` → `isApplicableNode()`, `findViolations()`, `buildRuleError()` helpers

| File | Method | Lines | Approach |
|------|--------|-------|----------|
| `Rules/Architecture/NoArtisanCallRule` | processNode | 40 | Extract call-matching + error building |
| `Rules/Architecture/NoDbFacadeRule` | processNode | 35 | Extract facade detection logic |
| `Rules/Architecture/NoEventDispatchOutsideApplicationRule` | processNode | 32 | Extract namespace/layer checking |
| `Rules/Architecture/NoEventDispatchOutsideApplicationRule` | isEventDispatch | 22 | Extract argument inspection |
| `Rules/Architecture/NoEventListenOutsideEventServiceProviderRule` | processNode | 40 | Extract listener detection |
| `Rules/Architecture/SchemaQualifiedTableNameRule` | processNode | 53 | Extract SQL parsing + table name matching |
| `Rules/Complexity/ExcessiveMethodLengthRule` | processNode | ~30 | Extract exclusion checks + measurement |
| `Rules/Complexity/ExcessiveClassLengthRule` | processNode | 40 | Extract class measurement + exclusion |
| `Rules/Complexity/ExcessiveParameterCountRule` | processNode | 31 | Extract param counting logic |
| `Rules/Exceptions/DomainExceptionMustExtendBaseRule` | processNode | 40 | Extract class hierarchy checks |
| `Rules/Exceptions/NoCatchReturnEmptyRule` | processNode | 23 | Minor extraction |
| `Rules/Infrastructure/NoSdkExceptionsInThrowsRule` | processNode | 44 | Extract throws-tag analysis |
| `Rules/Infrastructure/NoSdkExceptionsInThrowsRule` | buildUseMap | 24 | Extract import parsing |
| `Rules/Infrastructure/NoSdkExceptionsInThrowsRule` | resolveClassName | 24 | Extract name resolution |
| `Rules/Infrastructure/RowClassNotImportedOutsideQueriesRule` | processNode | 39 | Extract import/namespace checking |
| `Rules/Infrastructure/RowDtoMustBeInternalRule` | processNode | 24 | Minor extraction |
| `Rules/Infrastructure/ShopwiredModelMustImplementMappableRule` | processNode | 49 | Extract interface checking + model detection |
| `Rules/Jobs/JobHandleMustCatchThrowableRule` | processNode | 52 | Extract catch-block AST traversal |
| `Rules/Jobs/JobMustCallOnQueueRule` | processNode | 39 | Extract constructor analysis |
| `Rules/Jobs/JobMustImplementShouldQueueRule` | processNode | 23 | Minor extraction |
| `Rules/Jobs/JobNamingPrefixRule` | processNode | 32 | Extract prefix matching logic |
| `Rules/Jobs/JobRequiredMethodsRule` | processNode | 42 | Extract method presence checking |
| `Rules/Jobs/JobRequiredPropertiesRule` | processNode | 25 | Extract property checking |
| `Rules/Validation/ValidationResultMustNotOverrideOrFailRule` | processNode | 42 | Extract method override detection |
| `Rules/Validation/ValidationResultMustUseTraitRule` | processNode | 46 | Extract trait-use analysis |
| `Rules/Validation/ValidatorMustHaveValidateMethodRule` | processNode | 31 | Extract method presence + signature checking |
| `DevTools/GitHooks/AbstractPreCommitProcessHook` | handle | 24 | Extract process execution |
| `DevTools/GitHooks/AbstractProcessHook` | handle | 24 | Extract process execution |

**Checkpoint:** `make lint` + `make test-quick`

### Section 2: Providers (~23-25 entries, ~15 files)
**Risk:** Medium — DI wiring, changes must preserve `provides()` arrays and binding behavior
**Pattern:** Split long `register()`/`boot()` into sub-methods; where sub-methods are still long, further decompose

| File | Method | Lines | Approach |
|------|--------|-------|----------|
| `AppServiceProvider` | validateProductionEnvironment | 42 | Extract per-check validation helpers |
| `AuthServiceProvider` | register | 27 | Extract guard/provider setup |
| `BingAdsServiceProvider` | register | 33 | Extract binding groups |
| `CacheServiceProvider` | register | 37 | Extract store registrations |
| `ContactSubmissionServiceProvider` | register | 28 | Extract binding groups |
| `DatabaseServiceProvider` | register | 31 | Extract connection setup |
| `EventServiceProvider` | boot | 22 | Minor extraction |
| `GoogleAdsServiceProvider` | register | 38 | Extract binding groups |
| `HelpScoutServiceProvider` | register | 58 | Extract client/repository/service bindings |
| `InventoryServiceProvider` | register | 21 | Borderline — evaluate cohesion |
| `LinnworksServiceProvider` | register | 105 | Major: split into registerClients/Repos/UseCases/Dispatchers |
| `LinnworksServiceProvider` | provides | 22 | Rule-level exclusion or decompose |
| `MixpanelServiceProvider` | register | 61 | Extract client/service/dispatcher bindings |
| `QueueObservabilityServiceProvider` | boot | 32 | Extract event listener setup |
| `RateLimitServiceProvider` | boot | 38 | Extract per-limiter definitions |
| `RlsDatabaseServiceProvider` | boot | 33 | Extract RLS setup steps |
| `Schedule/AdsScheduleServiceProvider` | registerBingAdsSchedules | 36 | Extract per-job schedule definitions |
| `Schedule/AdsScheduleServiceProvider` | registerGoogleAdsSchedules | 39 | Extract per-job schedule definitions |
| `Schedule/MixpanelScheduleServiceProvider` | registerOrderSyncSchedules | 29 | Extract schedule definitions |
| `ShopwiredServiceProvider` | class-level (284) | — | Evaluate after method refactoring |
| `ShopwiredServiceProvider` | registerClients | 61 | Further split by client group |
| `ShopwiredServiceProvider` | registerFactories | 53 | Further split by factory type |
| `ShopwiredServiceProvider` | registerWebhookBindings | 24 | Minor extraction |
| `ShopwiredServiceProvider` | provides | 58 | Rule-level exclusion or array decomposition |
| `StorageServiceProvider` | register | 23 | Minor extraction |

**Checkpoint:** `make lint` + `make test-quick`

### Section 3: Presentation/Console/Commands (~20 entries, ~10 files)
**Risk:** Low — console commands are leaf nodes, dev/admin tooling
**Pattern:** Extract orchestration steps from `handle()`, split output formatting from logic

| File | Method | Lines | Approach |
|------|--------|-------|----------|
| `BackfillShopwiredOrdersCommand` | handle | 47 | Extract option parsing, range building, dispatch |
| `BackfillShopwiredOrdersCommand` | buildRanges | 21 | Borderline — evaluate |
| `BackfillShopwiredOrdersCommand` | buildJobTable | 21 | Borderline — evaluate |
| `Dev/TestPriceUpdateCommand` | handle | 68 | Extract product lookup, price calculation, display |
| `Dev/TestSlackNotificationCommand` | class-level (275) | — | Evaluate after method refactoring |
| `Dev/TestSlackNotificationCommand` | handleBasicTest | 52 | Extract credential validation, message building |
| `Dev/TestSlackNotificationCommand` | handleNotification | 29 | Extract type dispatch |
| `Dev/TestSlackNotificationCommand` | buildBasicNotification | 34 | Extract BlockKit section building |
| `Dev/TestSlackNotificationCommand` | sendContactFormFailed | 36 | Extract DTO construction |
| `GenerateVariantSkusCommand` | handle | 46 | Extract validation, execution, result display |
| `GenerateVariantSkusCommand` | handleExecutionError | 42 | Extract error formatting sections |
| `SetProductFreeDeliveryCommand` | handle | 49 | Extract product lookup, update, confirmation |
| `Shopwired/ShopwiredAuditOrderSyncCommand` | handle | 32 | Extract audit execution + display |
| `Shopwired/ShopwiredAuditOrderSyncCommand` | displayMissingDetails | 30 | Extract table formatting |
| `Shopwired/ShopwiredAuditProductSyncCommand` | handle | 24 | Minor extraction |
| `Shopwired/ShopwiredAuditProductSyncCommand` | displayMissingDetails | 28 | Extract table formatting |
| `TestShopwiredCostPriceCommand` | handle | 30 | Extract fetch + display |
| `UpdateSkusCommand` | handle | 45 | Extract mapping parse, validation, execution |
| `UpdateSkusCommand` | parseMapping | 30 | Extract validation + transformation |
| `VerifyApiConnectivityCommand` | handle | 44 | Extract match dispatch, batch execution |
| `VerifyApiConnectivityCommand` | verifyBingAds | 23 | Extract common verify pattern |
| `VerifyApiConnectivityCommand` | verifyGoogleAds | 24 | Extract common verify pattern |
| `VerifyApiConnectivityCommand` | verifyHelpScout | 23 | Extract common verify pattern |

**Checkpoint:** `make lint` + `make test-quick`

### Section 4: Presentation/Http (~21 entries, ~12 files)
**Risk:** Medium-high — auth middleware (ValidateSupabaseJwtMiddleware) is security-critical
**Pattern:** Extract validation/processing steps from middleware handle(); extract conditional sections from resources

| File | Method | Lines | Approach |
|------|--------|-------|----------|
| `Auth/Middleware/ValidateSupabaseJwtMiddleware` | handle | 90 | **Major:** extract validateAndParseToken(), resolveAuthenticatedUser() |
| `Auth/Middleware/ValidateSupabaseJwtMiddleware` | handleLocalBypass | 22 | Minor extraction |
| `Auth/Middleware/ValidateSupabaseJwtMiddleware` | shouldBypassAuth | 29 | Extract route/environment checks |
| `Auth/SupabaseJwtParser` | extractAppMetadata | 21 | Borderline — evaluate cohesion |
| `Auth/SupabaseJwtParser` | extractDepartments | 21 | Borderline — evaluate cohesion |
| `Auth/SupabaseJwtParser` | fromDecodedJwt | 22 | Borderline — evaluate cohesion |
| `Middleware/EnsureUserApprovedMiddleware` | handle | 37 | Extract approval checking logic |
| `Middleware/SetRlsContextMiddleware` | handle | 24 | Minor extraction |
| `Middleware/VerifyShopwiredWebhookSignatureMiddleware` | handle | 43 | Extract signature computation + validation |
| `Api/Controllers/ProductUpdateController` | updatePrices | 37 | Extract validation, execution steps |
| `Api/Controllers/ProductUpdateController` | updateFreeDelivery | 22 | Borderline — evaluate |
| `Api/InternalApiExceptionMapper` | message | 30 | Extract match expression or exception-to-message map |
| `Api/InternalApiExceptionMapper` | statusCode | 22 | Borderline — evaluate |
| `Api/Resources/ProductDetailResource` | toArray | 42 | Extract nested resource building |
| `Api/Resources/ProductResource` | baseFields | 34 | Extract computed field sections |
| `Api/Resources/CategoryDetailResource` | toArray | 31 | Extract nested sections |
| `Api/Resources/ProductVariationResource` | toArray | 23 | Borderline — evaluate if pure mapping |
| `Api/DTOs/ListProductsRequestDTO` | buildFilters | 21 | Borderline — evaluate |
| `HelpScout/Resources/ConversationResource` | toArray | 23 | Borderline — evaluate if pure mapping |
| `Controllers/FeedController` | show | 32 | Extract feed lookup + response building |
| `Controllers/FeedController` | findFeedConfig | 29 | Extract config search logic |

**Checkpoint:** `make lint` + `make test-quick`

### Section 5: Infrastructure/Persistence (~16 entries, 2 files)
**Risk:** High — EloquentGateway is core DB infrastructure used by all repositories
**Strategy:** Permanent class-level exclusion for EloquentGateway (approved). Refactor method-length violations. Param-count violations (6) stay in baseline.

| File | Method | Lines | Approach |
|------|--------|-------|----------|
| `EloquentGateway` | class-level (900) | — | **Permanent exclusion** — genuinely cohesive |
| `EloquentGateway` | batchUpsertMany | 71 | Extract chunk processing loop, fallback handler |
| `EloquentGateway` | upsertOneByOne | 65 | Extract per-row processing, error accumulation |
| `EloquentGateway` | deleteWhereInAndNotIn | 36 | Extract query building, log preparation |
| `EloquentGateway` | paginate | 35 | Extract query building + scope application |
| `EloquentGateway` | deleteWhereNotIn | 32 | Extract query + logging |
| `EloquentGateway` | deleteWhereIn | 25 | Extract query + logging |
| `EloquentGateway` | insertMany | 24 | Extract batch logic |
| `EloquentGateway` | insertOne | 24 | Extract insert + ID extraction |
| `EloquentGateway` | deleteWhere | 24 | Extract query + logging |
| `EloquentGateway` | reconcileWhereNotIn | 25 | Extract reconciliation logic |
| `EloquentGateway` | updateWhere | 22 | Borderline — evaluate |
| `EloquentGateway` | 6x param-count | — | **Stay in baseline** |
| `EscalationsConfigRepository` | get | 24 | Extract config parsing |

**Checkpoint:** `make lint` + `make test-quick`

### Section 6: Infrastructure Misc (~10 entries, ~8 files)
**Risk:** Medium — AbstractEloquentRepository is a base class

| File | Method | Lines | Approach |
|------|--------|-------|----------|
| `AbstractEloquentRepository` | class-level (258) | — | **Permanent exclusion** (approved) |
| `AbstractEloquentRepository` | saveMany | 44 | Extract per-entity save loop, error collection |
| `DatabaseGateway` | extractSqlState | 22 | Borderline — evaluate |
| `DatabaseGateway` | handleQueryException | 21 | Borderline — evaluate |
| `Jobs/Middleware/HandleApiExceptions` | handle | 21 | Borderline — evaluate |
| `Jobs/Shopwired/UpdateShopwiredAddToSaleJob` | handle | 34 | Extract sale-update orchestration |
| `Jobs/Shopwired/UpdateShopwiredRemoveFromSaleJob` | handle | 43 | Extract removal orchestration |
| `Operations/Listeners/RecordPricePeriodListener` | handle | 34 | Extract price comparison + recording |
| `Operations/Repositories/EloquentPricePeriodRepository` | recordPriceChange | 23 | Minor extraction |
| `Support/LockableCache` | rememberOrStale | 34 | Extract lock acquisition + refresh |
| `Support/LockableCache` | tryRefreshWithLock | 40 | Extract lock handling + fallback |
| `Support/RetryAfterParser` | parse | 45 | Extract per-format parsing (date, seconds, delta) |

**Checkpoint:** `make lint` + `make test-quick`

### Section 7: Application Misc (2 entries, 2 files)
**Risk:** Low

| File | Method | Lines | Approach |
|------|--------|-------|----------|
| `Application/Auth/TestUserPersonaResolver` | resolve | 30 | Extract persona lookup + construction |
| `Application/Support/GracefulCache` | remember | 28 | Extract cache miss handling |

**Final checkpoint:** `make lint` + `make test` (full suite)

## Expected Outcomes

| Metric | Estimate |
|--------|----------|
| Baseline entries removed | ~115-120 (method-length) + 2-3 (rule-level provides exclusion) |
| Permanent exclusions added | 2 class-level (EloquentGateway, AbstractEloquentRepository) |
| Remaining in baseline | ~6 param-count only (EloquentGateway) |
| Source files modified | ~60-70 |
| New files created | 0 |
| Behavioral changes | 0 |

## Default: Refactor Everything

The default stance is **every method-length entry gets refactored out of the baseline**. "Borderline" (21-24 lines) is not an excuse — evaluate on cohesion and extract where meaningful.

If a specific method proves genuinely difficult or risky to refactor (e.g., the extraction would harm readability or risk behavioral changes), **do not silently leave it in baseline**. Instead, collect these as a "deferred" list and present to the user at the end of the section for discussion.

Only param-count violations on EloquentGateway are pre-approved to stay in baseline (parameter objects would over-engineer internal infrastructure methods).

## Verification

1. Each section: `make lint` + `make test-quick`
2. Final: `make lint` + `make test` (full suite)
3. Count baseline entries before/after to verify removal count
4. `git diff` every file against base branch to verify no behavioral changes
5. Stop hooks will run `make fix`, `make lint`, `make test` automatically
