# Implementation Log: PHPStan & Static Analysis Improvements

**GitHub Issue**: #73
**Plan Document**: /Users/tom/.claude/plans/cheeky-seeking-barto.md
**Status**: In Progress
**Started**: 2025-12-16
**Completed**: —

## Overview

Comprehensive static analysis improvements including PHPStan checked exception enforcement, missing parameters, recommended extensions, TLint for Laravel conventions, and Psalm for taint analysis. Single PR with all 6 stages, fixing all violations (no baseline).

## Decision Log

### 2025-12-18 (Stage 5: TLint Laravel Conventions)

- **Decision**: Install `tightenco/tlint` v9.5.0 with Laravel preset
- **Why**: Enforces Laravel-specific coding conventions not covered by Pint (e.g., no leading slashes on routes, no `env()` outside config)
- **Config**: `tlint.json` with Laravel preset, excluded auto-generated files (IDE helpers, rector cache, storage)

- **Decision**: Use Laravel preset instead of Tighten preset
- **Why**: Less opinionated. Tighten preset enforces their company conventions (e.g., one-line returns), Laravel preset focuses on framework best practices.
- **Tradeoff**: Fewer rules enforced, but more aligned with broader Laravel community

- **Decision**: Exclude auto-generated and cache files from TLint scanning
- **Excluded**:
  - `_ide_helper.php`, `_ide_helper_models.php`, `.phpstorm.meta.php` — IDE helper generated files
  - `storage/` — Laravel storage directory
  - `.rector-cache/` — Rector cache files
  - `database/migrations/` — Migration boilerplate (standard Laravel convention)
  - `examples/` — Example files not part of main codebase
- **Why**: These files are auto-generated or follow different conventions

- **Violations Fixed**:
  - `NoLeadingSlashesOnRoutePaths`: Removed leading `/` from 11 route paths in `routes/api.php` and `routes/web.php`
  - `OneLineBetweenClassVisibilityChanges`: Fixed 2 test files with missing blank lines between visibility groups
  - Class member ordering: Moved constants before properties in `CustomerClientTest.php` and `SyncBingAdsToMixpanelJobTest.php`
- **Files Modified**: `routes/api.php`, `routes/web.php`, `CustomerClientTest.php`, `SyncBingAdsToMixpanelJobTest.php`

- **Decision**: Integrate TLint into parallel `make lint` pipeline with dual-speed targets
- **Why**: Full TLint scan (~7s on 570 files) is too slow for pre-commit; `tests/` directory alone takes 4.4s
- **Performance Optimization**:
  - `make tlint` — Fast (~2.6s): Scans only `app/` + `routes/` (main code + route conventions)
  - `make tlint-full` — Thorough (~7s): Scans entire codebase (config, bootstrap, tests, etc.)
- **Integration**: `lint` uses fast version; `lint-full` uses thorough version
- **Git Hook**: Created `TLintPrePushHook` class to run full TLint scan on pre-push
- **Tradeoff**: Pre-commit won't catch TLint issues in tests/config, but pre-push will

### 2025-12-18 (Stage 4: Optional Strict Packages)

- **Decision**: Install `kcs/phpstan-strict-rules` instead of `thecodingmachine/phpstan-strict-rules`
- **Why**: kcs is the PHPStan 2.x compatible fork. thecodingmachine/phpstan-strict-rules is abandoned and incompatible.
- **Rules enforced**: `MustRethrowRule` (checked exceptions in catch blocks must be re-thrown or marked with `// @ignoreException`)

- **Decision**: Use `// @ignoreException` comments instead of `@throws` for intentional exception swallowing
- **Why**: kcs pattern for documenting intentional exception handling. 16 catch blocks across codebase use this pattern.
- **Files Modified**: BingAdsTransport, ConfigCommandHookRegistry, DoofinderItemTransformer, GoogleAdsRowTransformer, LinnworksHttpTransport, LockableCache, MixpanelHttpTransport, ShopwiredHttpTransport, HelpScoutHttpTransport, JobRateLimiter
- **Tradeoff**: Comment-based enforcement vs explicit @throws documentation

- **Decision**: Install `symplify/phpstan-rules` with explicit services include
- **Why**: Services must be loaded before rules (PHPStan auto-discovery loads after manual includes)
- **Config**: Included `services.neon`, `static-rules.neon`, `naming-rules.neon`. Skipped `code-complexity-rules.neon` (overlaps with tomasvotruba/cognitive-complexity)

- **Decision**: Disable 4 symplify rules incompatible with Laravel/PHP conventions
- **Rules disabled**:
  - `symplify.explicitAbstractPrefixName` — PHP convention doesn't mandate "Abstract" prefix
  - `symplify.requireExceptionNamespace` — We use Domain-specific exception namespaces, not `Exception/`
  - `symplify.requireAttributeName` — PHP attributes don't require "Attribute" suffix
  - `symplify.forbiddenStaticClassConstFetch` — Valid uses in immutable DTOs (e.g., `self::MAX_COUNT`)
- **Tradeoff**: Less strict naming but follows PHP/Laravel ecosystem conventions

- **Decision**: Rename internal interfaces to consistently use "Interface" suffix
- **Why**: Codebase convention is all interfaces have "Interface" suffix. Consistency over mixed naming.
- **Renamed**:
  - `DomainConvertible` → `DomainConvertibleInterface`
  - `PaginatableQueryParams` → `PaginatableQueryParamsInterface`
- **Files Modified**: 18 files (interfaces + all implementers)

- **Decision**: Add PHPArkitect exceptions for Infrastructure-internal interfaces
- **Why**: `DomainConvertibleInterface` and `PaginatableQueryParamsInterface` are internal contracts (`@internal`) for Infrastructure layer only. They don't cross layer boundaries, so the "interfaces in Domain/Application" rule doesn't apply.
- **Modified**: `phparkitect.php` Rules 5 and 6 to allow these specific interfaces

### 2025-12-18 (Stage 3: Recommended Extensions)

- **Decision**: Install `spaze/phpstan-disallowed-calls` with 4 rulesets
- **Rulesets**: disallowed-dangerous-calls, disallowed-execution-calls, disallowed-insecure-calls, disallowed-loose-calls
- **Why**: Prevents security vulnerabilities (exec, shell_exec), type coercion bugs (in_array without strict), and dangerous functions

- **Decision**: Install `tomasvotruba/cognitive-complexity` with thresholds (function=10, class=50)
- **Why**: Enforces readable, maintainable functions. SonarQube default is 15, we chose stricter.
- **Refactors Required**: 5 methods exceeded threshold

- **Decision**: Extract `DoofinderItemTransformer` from `DoofinderFeedProcessor`
- **Why**: CC 15→7 achieved by separating item transformation logic (field formatting, validation helpers)
- **Pattern**: Single Responsibility - processor handles orchestration, transformer handles data mapping

- **Decision**: Extract validation helpers in `GoogleAdsRowTransformer::toCampaignMetrics()`
- **Why**: CC 22→10 by extracting `validateNestedObjects()`, `validateStringField()`, `validateIntField()`, `validateFloatField()`
- **Pattern**: Each field validation becomes a single-purpose method

- **Decision**: Extract config validators in `MixpanelClientFactory::createConfig()`
- **Why**: CC 11→10 by extracting `requireString()`, `requireInt()`, `requireLookupTables()`
- **Pattern**: Type-specific validation methods reduce nested conditionals

- **Decision**: Extract SOAP extractors in `BingAdsTransport::extractErrorCode()`
- **Why**: CC 11→10 by extracting `extractFromApiFaultDetail()`, `extractFromAdApiFaultDetail()`
- **Lesson**: PHPDoc type annotations are lexically scoped - extracted methods need their own `@param` object shape annotations

- **Decision**: Extract `handlePoolResult()` in `ShopwiredHttpTransport::poolPost()`
- **Why**: CC 11→10 by separating result handling (error translation, logging) from iteration logic

- **Decision**: Install `tomasvotruba/type-coverage` with 99% thresholds
- **Why**: Codebase already at 99%+ coverage (return, param, property). Threshold prevents regression.
- **No errors**: Confirms disciplined typing throughout

- **Decision**: Install `staabm/phpstan-todo-by` for TODO expiration enforcement
- **Why**: Prevents TODO accumulation. Future TODOs must have expiration dates.
- **Cleanup**: Removed obsolete "Issue #73 Stage 2" TODO (Stage 2 complete)

- **Lesson**: Test with `make lint` not just `phpstan analyse app/`
- **Why**: Full lint includes config/, routes/, database/ - baseline patterns needed for those paths
- **Tradeoff**: Running subset analysis during dev can miss issues caught by full lint

### 2025-12-18 (Stage 2: Missing PHPStan Parameters)

- **Decision**: Add 4 strict PHPStan parameters
- **Parameters**:
  - `checkUninitializedProperties: true` — Report typed properties not initialized in constructor
  - `checkBenevolentUnionTypes: true` — Stricter handling of `array-key`, `array<mixed>` etc.
  - `reportPossiblyNonexistentGeneralArrayOffset: true` — Report `$arr[$key]` where key might not exist
  - `reportPossiblyNonexistentConstantArrayOffset: true` — Report `$arr['key']` where 'key' might not exist
- **Initial Errors**: 24

- **Decision**: Use abstract methods instead of properties for DevTools GitHooks `$name`
- **Why**: Properties with framework injection (`setCommand()` called by Laravel) can't satisfy `checkUninitializedProperties`. Abstract method pattern is cleaner and PHPStan-friendly.
- **Files Modified**: BasePreCommitProcessHook, BaseProcessHook, and 6 child hook classes

- **Decision**: Add `ignoreErrors` for DevTools `$command` property
- **Why**: Laravel's Command framework injects `$command` via `setCommand()` before `handle()`. PHPStan can't verify this framework contract. Added identifier-based ignore for `property.uninitialized` in `app/DevTools/GitHooks/Base*Hook.php`.
- **Tradeoff**: Single, narrow suppress for verified framework behavior

- **Decision**: Fix DataCollection generics from `int` to `int|string`
- **Why**: `Spatie\LaravelData\DataCollection::all()` returns `array<int|string, T>` not `array<int, T>`. PHPStan's `checkBenevolentUnionTypes` catches this inaccuracy.
- **Files Modified**: ShopwiredResponseParserTrait, HelpScoutResponseParser, ReviewsIoClient

- **Decision**: Use `assert()` for invariant offset access checks
- **Why**: User chose asserts — "if it fails in prod it's a LogicException anyway". PHP `assert()` compiles out in production (`zend.assertions=-1`) but documents code contracts for PHPStan.
- **Files Modified**: CachingHelpScoutService, BingAdsTransport, BingAdsCsvTransformer, ReviewsIoClient, ShopwiredHttpTransport

- **Decision**: Use `InvalidConfigurationException` for MixpanelClient tableKey validation
- **Why**: `replaceLookupTable()` receives a `$tableKey` that must exist in `lookupTableIds` config. This is a configuration error (missing lookup table ID), not a runtime failure.
- **Tradeoff**: Changed from `Assert::keyExists` (throws InvalidArgumentException) to explicit check with domain exception

- **Decision**: Refactor ProcessProductSearchFeedUseCase config validation for PHPStan
- **Why**: Cascading `is_string()` checks don't narrow types for PHPStan. Extracted values to variables first, then validated.
- **Tradeoff**: More verbose but PHPStan-safe

### 2025-12-17 (Session 3)

- **Decision**: Linnworks batch - removed stale `@phpstan-ignore` directives
- **Why**: After proper `@throws` were added in previous session, the `missingType.checkedException` ignores on lines 65 and 107 of `LinnworksHttpTransport.php` became orphaned (PHPStan errors for ignoring non-existent errors)
- **Files Modified**: 1 file (LinnworksHttpTransport.php)

- **Decision**: Mixpanel batch - change 404 handler from `ResourceNotFoundException` to `InvalidApiRequestException`
- **Why**: Mixpanel endpoints are fixed URLs (not dynamic resource identifiers). A 404 means we called a wrong endpoint — a programming error, semantically equivalent to 400.
- **Tradeoff**: Differs from Linnworks/ShopWired pattern, but semantically correct for this API.

- **Decision**: Add `@throws RuntimeException` to private `createBaseRequest()` only
- **Why**: Existing `catch (Exception $e)` block already catches and translates to `ExternalServiceUnavailableException`. No change needed to public API.
- **Files Modified**: 8 files (MixpanelHttpTransport, MixpanelClient, MixpanelClientInterface, LookupTableProviderInterface, CampaignLookupTableProvider, SyncAdSpendUseCase, SyncLookupTableUseCase)

### 2025-12-17 (Session 2)

- **Decision**: Complete HelpScout integration `@throws` documentation as first batch
- **Why**: Self-contained integration spanning Infrastructure → Application layers, establishes pattern for remaining batches
- **Files Modified**: 8 files (UsersClient, MailboxesClient, ConversationsClient, HelpScoutHttpTransport, CachingHelpScoutService, MailboxEnrichmentService, GetEscalationsUseCase, GetConversationsUseCase)

- **Decision**: Document all 4 HelpScout exceptions throughout the chain
- **Why**: Transport layer throws AuthenticationExpiredException, ExternalServiceUnavailableException, InvalidApiRequestException, InvalidApiResponseException - all must propagate up
- **Tradeoff**: Verbose but accurate documentation

- **Decision**: Add `@throws RuntimeException` to HelpScoutHttpTransport.createRequest()
- **Why**: SDK's getAuthHeader() can throw RuntimeException on token refresh failure - must be documented
- **Tradeoff**: RuntimeException is unchecked but still needs @throws for clarity

- **Decision**: Add `@throws` to private methods that throw/propagate
- **Why**: PHPStan's checked exception rules apply to private methods too (executeAndProcess, getMailboxNameMap)
- **Tradeoff**: More documentation overhead

### 2025-12-17 (Session 1)

- **Decision**: Create `LockableCacheInterface` with `@param-immediately-invoked-callable` annotation
- **Why**: Enables PHPStan to understand exception propagation from closures without requiring `@throws Exception` on cache methods
- **Tradeoff**: PHPStan-specific annotation, less portable to other tools

- **Decision**: Remove `@throws Exception` from LockableCacheInterface methods
- **Why**: The cache methods don't *throw* exceptions - they *propagate* exceptions from callbacks. Semantic difference matters for checked exceptions.
- **Tradeoff**: Interface doesn't document what exceptions might emerge, but `@param-immediately-invoked-callable` handles it

- **Decision**: Use `@template TValue` without bounds (not `@template TValue of non-null`)
- **Why**: PHPStan doesn't support `non-null` as a template bound, causes parse errors
- **Tradeoff**: Can't statically enforce non-null factory returns at compile time

- **Decision**: Fix Config VO `@throws` from `RuntimeException` to `InvalidConfigurationException`
- **Why**: PHPStan resolved `RuntimeException` to namespace-local class (doesn't exist). Also, code actually throws `InvalidConfigurationException`.
- **Tradeoff**: None - this was a documentation bug

- **Decision**: Temporarily disable checked exception rules in phpstan.neon
- **Why**: Allows committing accumulated Stage 1 work while ~176 @throws propagation errors remain
- **Tradeoff**: Will need Stage 2 to complete @throws documentation

### 2025-12-16

- **Decision**: Create `InvalidConfigurationException` extending `LogicException`
- **Why**: Config errors are deployment-time issues (wrong env vars), not runtime failures. LogicException family is "unchecked" per PHPStan config.
- **Tradeoff**: Renamed from `MissingConfigurationException` since it handles both missing AND invalid config

- **Decision**: Keep `InvalidArgumentException` for parameter bounds validation
- **Why**: Bounds validation (timeout 1-300s, retry 0-10) is different from config validation (empty strings, missing keys). Bounds errors are programming errors caught at call sites.
- **Tradeoff**: Two exception types in Config VOs, but semantically correct

- **Decision**: Keep `RuntimeException` for JWT "sub" claim validation
- **Why**: This validates user-provided JWT contents at request time, not config. It's a runtime failure from bad user input.
- **Tradeoff**: `ValidateSupabaseJwtMiddleware` now uses two exception types

- **Decision**: Keep `RuntimeException` for ZIP/file I/O errors in BingAdsTransport
- **Why**: These are transient I/O failures, not configuration errors. They should trigger retries.
- **Tradeoff**: Not unified with InvalidConfigurationException

## Progress Tracking

### Stage 1: Checked Exception Enforcement
- [x] Added exception config to phpstan.neon
- [x] Created `InvalidConfigurationException` in Domain
- [x] Updated 7 *ClientFactory files
- [x] Updated 6 *Config VOs (GoogleAds, BingAds, Mixpanel, Linnworks, Shopwired, ReviewsIo, HelpScout)
- [x] Updated 2 Service Providers (Storage, App)
- [x] Updated ValidateSupabaseJwtMiddleware (line 52 only)
- [x] Updated 7 ConfigTest files
- [x] Updated AppServiceProviderTest
- [x] Created `LockableCacheInterface` and `LockableCache` implementation
- [x] Created `CacheServiceProvider` with contextual bindings
- [x] Refactored BingAdsSessionManager to use LockableCacheInterface
- [x] Refactored LinnworksSessionManager to use LockableCacheInterface
- [x] Updated session manager tests
- [x] Temporarily disabled checked exception rules
- [x] **HelpScout @throws batch** (21 errors → 0) - complete
- [x] **Linnworks batch** - removed stale @phpstan-ignore directives
- [x] **Mixpanel @throws batch** (12 errors → 0) - complete
- [x] **Remaining @throws propagation** - complete (59 errors fixed)
  - Google Ads, Reviews.io, Bing Ads, Supabase, Doofinder infrastructure
  - HelpScoutController, FeedController
  - All Jobs (SyncBingAds, SyncGoogleAds, SyncCampaignLookup, ProcessProductSearchFeed)
  - HorizonBasicAuthMiddleware, HorizonServiceProvider, TelescopeServiceProvider
  - DevTools GitHooks (BasePreCommitProcessHook, BaseProcessHook)
  - UserFactory
  - Migrations excluded via phpstan.neon (deployment scripts, no value in documenting)

### Stage 2: Missing PHPStan Parameters — COMPLETE
- [x] Added 4 strict parameters to phpstan.neon
- [x] Fixed 4 uninitialized property errors (DevTools GitHooks)
- [x] Fixed 6 DataCollection generic errors (int → int|string)
- [x] Fixed 14 offset access errors (asserts, refactoring, InvalidConfigurationException)
- **Total**: 24 errors → 0 errors

### Stage 3: Recommended Extensions — COMPLETE
- [x] Installed `spaze/phpstan-disallowed-calls` (4 security/quality rulesets)
- [x] Installed `tomasvotruba/cognitive-complexity` (function=10, class=50)
- [x] Refactored 5 methods exceeding CC threshold:
  - DoofinderFeedProcessor (extracted DoofinderItemTransformer)
  - GoogleAdsRowTransformer::toCampaignMetrics() (CC 22→10)
  - MixpanelClientFactory::createConfig() (CC 11→10)
  - BingAdsTransport::extractErrorCode() (CC 11→10)
  - ShopwiredHttpTransport::poolPost() (CC 11→10)
- [x] Installed `tomasvotruba/type-coverage` (99% thresholds - already passing)
- [x] Installed `staabm/phpstan-todo-by` (removed obsolete Stage 2 TODO)

### Stage 4: Optional Strict Packages — COMPLETE
- [x] Installed `kcs/phpstan-strict-rules` (PHPStan 2.x fork of thecodingmachine)
- [x] Added `// @ignoreException` comments to 16 intentional exception swallowing catch blocks
- [x] Installed `symplify/phpstan-rules` (services + static-rules + naming-rules)
- [x] Disabled 4 symplify rules incompatible with Laravel/PHP conventions
- [x] Renamed 2 interfaces for consistent "Interface" suffix
- [x] Updated PHPArkitect rules to allow Infrastructure-internal interfaces

### Stage 5: TLint Laravel Conventions — COMPLETE
- [x] Installed `tightenco/tlint` v9.5.0
- [x] Created `tlint.json` with Laravel preset and exclusions
- [x] Fixed 11 `NoLeadingSlashesOnRoutePaths` violations in route files
- [x] Fixed `OneLineBetweenClassVisibilityChanges` in 2 test files
- [x] Integrated TLint into Makefile with dual-speed targets:
  - `tlint` (fast ~2.6s) for pre-commit via `make lint`
  - `tlint-full` (~7s) for pre-push via `make lint-full`
- [x] Created `TLintPrePushHook` class for git pre-push integration

### Stage 6: Not Started
- [ ] Psalm taint analysis

## PHPStan Error Count

| Date | Errors | Notes |
|------|--------|-------|
| Start | 211 | Initial after enabling checked exceptions |
| Session 1 | 185 | After Factory and Config updates |
| Session 2 | 176 | After Service Provider and Middleware updates |
| Session 3 | 0 | Temporarily disabled checked exception rules, all other errors fixed |
| Session 4 | 171 | Re-enabled checked exceptions, HelpScout batch complete |
| Session 5 | 146 | Linnworks + Mixpanel batches complete (25 errors fixed) |
| Session 6 | 0 | **All @throws complete** - 59 remaining errors fixed, migrations excluded |
| Stage 2 | 24→0 | **Missing parameters enabled** - 4 strict checks, 24 violations fixed |
| Stage 3 | 5→0 | **Extensions added** - 4 packages, 5 CC refactors, 99% type coverage confirmed |
| Stage 4 | 89→0 | **Strict packages** - kcs + symplify, 16 @ignoreException, 4 rules disabled, 2 interfaces renamed |
| Stage 5 | — | **TLint added** - 11 route fixes, 2 test file fixes, integrated into Makefile |

## Deviations from Plan

- Created `InvalidConfigurationException` - not in original plan, emerged as better pattern than RuntimeException
- Plan suggested excluding migrations/DevTools - instead choosing to add @throws (more correct)
- Created `LockableCacheInterface` and `LockableCache` - emerged from session manager refactoring, provides thundering herd protection pattern
- Temporarily disabled checked exception rules - allows committing Stage 1 progress while @throws work continues in Stage 2
- Stage 4: Used `kcs/phpstan-strict-rules` instead of `thecodingmachine/phpstan-strict-rules` - thecodingmachine is abandoned, kcs is the PHPStan 2.x fork
- Stage 4: Renamed internal interfaces to add "Interface" suffix - plan didn't anticipate this, emerged from symplify naming rule conflict

## Blockers / Open Questions

- [x] `AppServiceProviderTest` needs update for InvalidConfigurationException - done
- [x] How to handle HttpException throws in HorizonBasicAuthMiddleware? - documented `@throws HttpException` and `@throws HttpResponseException`
- [x] Laravel migrations throw RuntimeException from Schema facade - excluded via phpstan.neon (deployment scripts, no value)
- [x] Complete @throws documentation for ~176 remaining locations - **DONE** (0 errors remaining)

## Technical Notes

- `InvalidConfigurationException` takes `configKey` as first param for debugging
- LogicException hierarchy is unchecked in PHPStan (won't require @throws)
- RuntimeException from user input (JWT validation) stays as RuntimeException

## PR Notes

### What
Enable PHPStan checked exception enforcement and standardize exception usage across configuration validation.

### Why
- Catch missing @throws at compile time, not runtime
- Distinguish config errors (deployment) from runtime errors (transient)
- Prepare codebase for additional static analysis tools

### Key Decisions
- `InvalidConfigurationException` extends LogicException (unchecked)
- Config validation uses InvalidConfigurationException
- Parameter bounds validation keeps InvalidArgumentException
- User input validation keeps RuntimeException

### Testing
- All Config unit tests updated for new exception type
- Service Provider tests to be updated
