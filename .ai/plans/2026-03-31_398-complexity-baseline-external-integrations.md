# Plan: Issue #398 — Reduce PHPStan complexity baseline: External integrations (91 errors)

## Context

PR #395 added custom PHPStan complexity rules (`alz.excessiveMethodLength` ≤20 lines, `alz.excessiveClassLength` ≤250 lines, `alz.excessiveParameterCount` ≤4 params) with a baseline of 469 pre-existing violations in `phpstan-complexity-baseline.neon`. This issue covers 91 of those violations across external integrations.

After investigating all 91 violations with the user, the final split is:
- **43 → directory-level permanent exclusions** (Mixpanel, BingAds, GoogleAds, AdSpend)
- **~6 → rule-level exclusion** (mapper method names excluded in `ExcessiveMethodLengthRule`)
- **~42 → genuine refactoring** (all remaining violations)

---

## Phase 1: Rule-Level Exclusion — Mapper Methods

**Modify `ExcessiveMethodLengthRule`** to skip mapper method names. These are structural field-mapping methods that naturally grow with field count and have no extractable logic.

### File: `app/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRule.php`

Add excluded method names constant and early return:

```php
private const array EXCLUDED_METHODS = [
    'toDomain',
    'fromModel',
    'toModelAttributes',
    'toSdk',
    'fromDomain',
];
```

Add check after namespace check:
```php
if (\in_array($node->name->name, self::EXCLUDED_METHODS, true)) {
    return [];
}
```

### Impact
Resolves **30 baseline entries across ALL four issues** (#396-#399), not just #398. For this issue specifically:
- `ContactSubmissionMapper::fromModel()` (+18)
- `ContactSubmissionMapper::toModelAttributes()` (+21)
- `CustomerMapper::toSdk()` (+3)
- `SnoozeResponse::toDomain()` (+1)
- `ConversationResponse::toDomain()` (+17) — also gets refactored (date extraction), but rule exclusion is the safety net

Remove all matching baseline entries after the rule change.

---

## Phase 2: Class-Level Permanent Exclusions (3 entries)

Only **class-level** violations in Mixpanel/Ads get permanent exclusions (specific file paths, not directory wildcards). The 40 method-level and parameter-count violations **stay in the baseline** — this preserves enforcement on new code in these directories.

### Config to add in `phpstan.neon` ignoreErrors section:

```yaml
# Mixpanel and Ads classes: too large to decompose safely (#398)
-
    identifier: alz.excessiveClassLength
    paths:
        - app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php
        - app/Infrastructure/BingAds/BingAdsTransport.php
        - app/Infrastructure/Mixpanel/MixpanelClient.php
```

### Baseline changes:
- **Remove** 3 `alz.excessiveClassLength` entries for the above files
- **Keep** all 40 `alz.excessiveMethodLength` and `alz.excessiveParameterCount` entries in the baseline as-is

---

## Phase 3: Refactoring

All remaining violations get refactored. Grouped by domain for implementation order.

### Group 1: HelpScout (15 methods)

| # | File | Method | Over | Approach |
|---|------|--------|------|----------|
| 1 | `app/Application/HelpScout/Services/CachingHelpScoutService.php` | `getConversationsBatch()` | +14 | Extract `separateCachedAndUncached()` |
| 2 | `app/Application/HelpScout/Services/CachingHelpScoutService.php` | `processFetchedBatch()` | +2 | Extract enrichment + caching step |
| 3 | `app/Application/HelpScout/Support/ConversationSorter.php` | `byStatusAndDate()` | +2 | Extract comparator to `compareByStatusAndDate()` static method |
| 4 | `app/Application/HelpScout/UseCases/GetEscalationsUseCase.php` | `buildQueries()` | +14 | Extract `buildMailboxQueries(int $mailboxId)` (called 2x) |
| 5 | `app/Infrastructure/HelpScout/Clients/ConversationWriteClient.php` | `createConversationFromCustomer()` | +3 | Extract null-check validation helper |
| 6 | `app/Infrastructure/HelpScout/Clients/ConversationsClient.php` | `getConversationsBatch()` | +17 | Extract `parsePoolResponses()` |
| 7 | `app/Infrastructure/HelpScout/HelpScoutClientFactory.php` | `createConfig()` | +6 | Extract `validateMailboxes()` |
| 8 | `app/Infrastructure/HelpScout/HelpScoutConfig.php` | `__construct()` | +12 | Extract `validateMailboxes()`, `validateTimeout()`, `validateRetryAttempts()` |
| 9 | `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` | `handlePoolGetResult()` | +9 | Extract `handleThrowableResult()` |
| 10 | `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` | `poolGet()` | +10 | Extract `processPoolResults()` |
| 11 | `app/Infrastructure/HelpScout/HelpScoutResponseParser.php` | `extractEmbedded()` | +7 | Extract `getEmbeddedRoot()` and `getEmbeddedResource()` |
| 12 | `app/Infrastructure/HelpScout/Responses/ConversationResponse.php` | `toDomain()` | +17 | Extract `parseDates()` (also covered by rule exclusion as safety net) |
| 13 | `app/Infrastructure/HelpScout/Services/NameFormatterService.php` | `parse()` | +12 | Extract `buildResult()` for fallback + middle name logic |
| 14 | `app/Infrastructure/HelpScout/Services/PhoneFormatterService.php` | `format()` | +9 | Extract `formatParsedNumber()` |
| 15 | `app/Infrastructure/HelpScout/Support/SdkExceptionTranslator.php` | `execute()` | +9 | Extract `handleAuthError()`, `handleValidationError()`, `handleConnectionError()` |

### Group 2: ReviewsIo (6 methods + 1 Postgres view)

| # | File | Method | Over | Approach |
|---|------|--------|------|----------|
| 16 | `app/Application/ReviewsIo/UseCases/SyncProductRatingsUseCase.php` | `execute()` | +28 | Extract `processRatingBatches()` for loop + buffer |
| 17 | `app/Application/ReviewsIo/UseCases/UpdateShopwiredRatingsUseCase.php` | `execute()` | +32 | Extract `updateProductsAndTrackFailures()` |
| 18 | `app/Infrastructure/ReviewsIo/ReviewsIoClient.php` | `parseArrayResponse()` | +2 | Extract `throwInvalidResponse()` to deduplicate log-and-throw |
| 19 | `app/Infrastructure/ReviewsIo/ReviewsIoClientFactory.php` | `create()` | +4 | Extract `validateCredentials()` |
| 20 | `app/Infrastructure/ReviewsIo/ReviewsIoConfig.php` | `__construct()` | +13 | Extract 5 validation methods |
| 21 | `app/Infrastructure/ReviewsIo/Repositories/EloquentProductRatingRepository.php` | `getProductsWithChangedRatings()` | +14 | **Postgres view + dedicated read-side repository** (see below) |

**ReviewsIo SQL → Postgres View refactoring:**
1. Create migration: `domain.products_with_changed_ratings` view encapsulating the CTE query
2. Create `Application/Contracts/ReviewsIo/ChangedRatingQueryRepositoryInterface.php`
3. Create `Infrastructure/ReviewsIo/Repositories/ChangedRatingQueryRepository.php` — trivial `SELECT * FROM domain.products_with_changed_ratings` + DTO hydration
4. Update `SyncProductRatingsUseCase` (or `UpdateShopwiredRatingsUseCase`) to depend on new interface
5. Remove `getProductsWithChangedRatings()` from `EloquentProductRatingRepository`
6. Register binding in service provider

### Group 3: Feeds (5 methods + 1 class extraction + 1 config refactor)

| # | File | Method | Over | Approach |
|---|------|--------|------|----------|
| 22 | `app/Infrastructure/Feeds/DoofinderFeedProcessor.php` | **[CLASS]** | +213 | Extract `DoofinderStreamingTransformer` class |
| 23 | `app/Infrastructure/Feeds/DoofinderFeedProcessor.php` | `fetchSourceFeed()` | +41 | Extract `handleMetaRefreshRedirect()` + `validateRedirectDepth()` |
| 24 | `app/Infrastructure/Feeds/DoofinderFeedProcessor.php` | `process()` | +34 | Extract phase methods: fetch+transform, read+upload, log completion |
| 25 | `app/Infrastructure/Feeds/DoofinderItemTransformer.php` | `resolveTitleElements()` | +14 | Extract `resolveGoogleNamespacedTitleElements()` |
| 26 | `app/Infrastructure/Feeds/DoofinderItemTransformer.php` | `throwMissingElementException()` | +4 | Refactor: accept `$hasTitle`/`$hasDTitle` as bool params, simplify branching |

Note: `extractStatsFromTempFile()` (+7) and `transformFeedToTempFile()` (+16) auto-resolve when moved to `DoofinderStreamingTransformer`.

**Config refactor — `ProcessProductSearchFeedUseCase::validateConfig()`:**
- Create `Infrastructure/Feeds/DoofinderConfig.php` following existing `*Config` pattern (HelpScoutConfig, ReviewsIoConfig, etc.)
- Move config reading + validation from UseCase to `DoofinderConfig.__construct()`
- Create `DoofinderClientFactory` (or add to service provider) to wire config
- Inject `DoofinderConfig` into `ProcessProductSearchFeedUseCase` via constructor
- This also resolves the existing `alz.noConfigHelper` PHPStan ignore on this file

### Group 4: ContactSubmission (4 methods)

| # | File | Method | Over | Approach |
|---|------|--------|------|----------|
| 27 | `app/Application/ContactSubmission/ContactSubmissionToConversationCommandTransformer.php` | `buildBody()` | +2 | Extract `appendProductAndMetadata()` |
| 28 | `app/Application/ContactSubmission/ContactSubmissionToConversationCommandTransformer.php` | `formatProduct()` | +7 | Extract `formatProductLine(label, value)` helper + `array_filter` |
| 29 | `app/Application/ContactSubmission/CleanupStaleContactActionsUseCase.php` | `execute()` | +16 | Extract `processBatch()` returning reset/failed counts |
| 30 | `app/Application/ContactSubmission/ProcessContactSubmissionUseCase.php` | `execute()` | +16 | Extract `ensureNotAlreadyProcessed()` + `createConversationAndNotify()` |
| 31 | `app/Application/ContactSubmission/ProcessContactSubmissionUseCase.php` | `addEmailValidationNoteIfInvalid()` | +5 | Extract `addNonBlockingNote(conversationId, noteText)` reusable helper |

### Group 5: Notifications (5 methods)

| # | File | Method | Over | Approach |
|---|------|--------|------|----------|
| 32 | `app/Infrastructure/Notifications/Listeners/ProductPricingUpdatedSlackListener.php` | `handle()` | +10 | Extract `enrichProductContext()` + `resolveSaleContext()` |
| 33 | `app/Infrastructure/Notifications/Slack/AdminAlertNotification.php` | `toSlack()` | +5 | Extract optional context section building |
| 34 | `app/Infrastructure/Notifications/Slack/ProductPricingUpdatedNotification.php` | `buildPriceChangeList()` | +5 | Extract `formatPriceChangeLine()` + `buildOverflowSuffix()` |
| 35 | `app/Infrastructure/Notifications/Slack/ProductPricingUpdatedNotification.php` | `toSlack()` | +14 | Extract conditional sale context + product button sections |
| 36 | `app/Infrastructure/Notifications/Slack/ProductPricingUpdatedNotification.php` | `buildAddToSaleContext()` | +2 | Extract conditional field appending logic |
| 37 | `app/Infrastructure/Notifications/Slack/VariantSkusGeneratedNotification.php` | `toSlack()` | +3 | Extract conditional variants section |

### Group 6: Storage (3 methods — shared helper)

| # | File | Method | Over | Approach |
|---|------|--------|------|----------|
| 38 | `app/Infrastructure/Storage/S3StorageClient.php` | `put()` | +7 | Extract `handleStorageException(operation, path, exception)` shared helper |
| 39 | `app/Infrastructure/Storage/S3StorageClient.php` | `exists()` | +1 | Uses shared `handleStorageException()` |
| 40 | `app/Infrastructure/Storage/S3StorageClient.php` | `temporaryUrl()` | +1 | Uses shared `handleStorageException()` |
| 41 | `app/Infrastructure/Notifications/SlackChatNotificationClient.php` | `send()` | +2 | Extract `resolveChannel(configKey): string` |

---

## Phase 4: Baseline Cleanup

After all rule changes, exclusions, and refactoring:
1. Remove all resolved entries from `phpstan-complexity-baseline.neon`
2. Run `make lint` to verify clean pass
3. Run `make test` to verify no behavioral changes

---

## Verification

1. `make lint` — all linters pass
2. `make test` — full test suite passes
3. Baseline entry count reduced by 91 (this issue) + bonus mapper entries from other issues

---

## Implementation Order

1. **Phase 1 + 2** — Rule change (mapper exclusion) + 3 class-level permanent exclusions. Config-only, no code changes.
2. **Phase 3, Group 6** — S3 storage shared helper. Small, isolated, good warm-up.
3. **Phase 3, Group 4** — ContactSubmission. Small scope, clear extractions.
4. **Phase 3, Group 5** — Notifications. Independent, clear extractions.
5. **Phase 3, Group 1** — HelpScout. Largest group (15 methods), but all isolated extractions.
6. **Phase 3, Group 2** — ReviewsIo. Includes Postgres view migration (most complex change).
7. **Phase 3, Group 3** — Feeds. Includes class extraction + config refactor (DoofinderConfig).
8. **Phase 4** — Final baseline cleanup + verification.

---

## Critical Files

### Config files to modify:
- `phpstan.neon` — add directory-level ignores
- `phpstan-complexity-baseline.neon` — remove resolved entries
- `app/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRule.php` — add mapper exclusions

### New files to create:
- `app/Infrastructure/Feeds/DoofinderConfig.php` — config value object
- `app/Infrastructure/Feeds/DoofinderStreamingTransformer.php` — extracted from DoofinderFeedProcessor
- `database/migrations/XXXX_create_products_with_changed_ratings_view.php` — Postgres view
- `app/Application/Contracts/ReviewsIo/ChangedRatingQueryRepositoryInterface.php` — interface
- `app/Infrastructure/ReviewsIo/Repositories/ChangedRatingQueryRepository.php` — implementation

### Files with heaviest refactoring:
- `app/Infrastructure/Feeds/DoofinderFeedProcessor.php` — class extraction (5 violations)
- `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` — 2 method extractions
- `app/Application/ReviewsIo/UseCases/SyncProductRatingsUseCase.php` — +28 over
- `app/Application/ReviewsIo/UseCases/UpdateShopwiredRatingsUseCase.php` — +32 over
