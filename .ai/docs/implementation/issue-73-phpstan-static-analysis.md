# Implementation Log: PHPStan & Static Analysis Improvements

**GitHub Issue**: #73
**Plan Document**: /Users/tom/.claude/plans/cheeky-seeking-barto.md
**Status**: In Progress
**Started**: 2025-12-16
**Completed**: —

## Overview

Comprehensive static analysis improvements including PHPStan checked exception enforcement, missing parameters, recommended extensions, TLint for Laravel conventions, and Psalm for taint analysis. Single PR with all 6 stages, fixing all violations (no baseline).

## Decision Log

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

### Stages 2-6: Not Started
- [ ] Stage 2: Missing PHPStan parameters
- [ ] Stage 3: Recommended extensions (spaze, cognitive-complexity, type-coverage, todo-by)
- [ ] Stage 4: Optional strict extensions (thecodingmachine, symplify)
- [ ] Stage 5: TLint Laravel conventions
- [ ] Stage 6: Psalm taint analysis

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

## Deviations from Plan

- Created `InvalidConfigurationException` - not in original plan, emerged as better pattern than RuntimeException
- Plan suggested excluding migrations/DevTools - instead choosing to add @throws (more correct)
- Created `LockableCacheInterface` and `LockableCache` - emerged from session manager refactoring, provides thundering herd protection pattern
- Temporarily disabled checked exception rules - allows committing Stage 1 progress while @throws work continues in Stage 2

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
