# Test Cleanup Plan

**Objective:** Remove tests that violate the Testing Strategy (tests/TestingStrategy.md) to reduce maintenance burden and align with the "test what matters" philosophy.

**Current State:** 170 test files
**Target State:** ~114 test files (after deletions) + 6 reduced files
**Estimated Reduction:** ~56 files deleted + ~35 tests removed from 6 files

---

## Pre-Removal Checklist

Before starting removal:
```bash
# Establish coverage baseline
make test-domain-coverage   # Record: Domain at X%
make test-app-coverage      # Record: Application at Y%
```

---

## Summary by Category

### Files to DELETE (Complete Removal)

| Category | Count | Rationale |
|----------|-------|-----------|
| Response DTO Tests | 18 | "Simple DTOs - No logic. Type system handles structure." |
| Infrastructure Client Unit Tests | 9 | Should be Integration tests only; existing Feature tests sufficient |
| Transport/Session Tests | 8 | "Trust the framework" for retry/HTTP details |
| Config Tests | 7 | Boot-time validation; fail-fast at startup |
| Query Params/Utilities | 9 | Internal implementation details |
| Application Enum Tests | 2 | PHPStan handles type validation |
| Misc Infrastructure | ~3 | Parsing internals, DTOs, factories |
| **Total DELETE** | **~56** | |

### Files to REDUCE (Keep File, Remove Tests)

| Category | Files | Action |
|----------|-------|--------|
| Domain Exception Tests | 4 | Keep 1 test (message format), remove 3-4 tests each |
| Application Caching Services | 2 | Keep ~5 tests, remove ~15-17 tests each |
| **Total REDUCE** | **6 files** | ~35 tests removed, files kept |

### Files to KEEP (Critical Review Findings)

| File | Reason to Keep |
|------|----------------|
| `tests/Unit/Providers/AppServiceProviderTest.php` | Security-critical production validation |
| `tests/Unit/Infrastructure/Support/ApiRetryStrategyTest.php` | Shared utility with business logic |
| Domain exception message format tests (1 per file) | Tests actual string interpolation logic |

---

## Phase 1: High-Confidence Deletions (37 files)

These clearly violate the Testing Strategy's explicit guidance and will be **completely deleted**.

### 1.1 Response DTO Tests (18 files)

**Strategy violation:** "Simple DTOs - No logic. Type system handles structure."

```
tests/Unit/Infrastructure/HelpScout/Responses/ConversationResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/ConversationsResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/CustomerResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/MailboxResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/UserResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/AssigneeResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/TagResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/SnoozeResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/PageResponseTest.php
tests/Unit/Infrastructure/HelpScout/Responses/CustomerWaitingSinceResponseTest.php
tests/Unit/Infrastructure/Shopwired/Responses/CategoryResponseTest.php
tests/Unit/Infrastructure/Shopwired/Responses/OrderResponseTest.php
tests/Unit/Infrastructure/Shopwired/Responses/CategoryImageResponseTest.php
tests/Unit/Infrastructure/Shopwired/Responses/CustomerResponseTest.php
tests/Unit/Infrastructure/Shopwired/Responses/OrderStatusResponseTest.php
tests/Unit/Infrastructure/Linnworks/Responses/StockItemResponseTest.php
tests/Unit/Infrastructure/Linnworks/Responses/SkuStockIdMappingResponseTest.php
tests/Unit/Infrastructure/ReviewsIo/Responses/RatingResponseTest.php
```

### 1.2 Infrastructure Client Unit Tests (9 files)

**Strategy violation:** "Don't unit test internal implementation" for Infrastructure. Use Integration tests at boundaries only.

```
tests/Unit/Infrastructure/HelpScout/Clients/ConversationsClientTest.php
tests/Unit/Infrastructure/HelpScout/Clients/MailboxesClientTest.php
tests/Unit/Infrastructure/HelpScout/Clients/UsersClientTest.php
tests/Unit/Infrastructure/Shopwired/Clients/OrderClientTest.php
tests/Unit/Infrastructure/Shopwired/Clients/StockClientTest.php
tests/Unit/Infrastructure/Shopwired/Clients/CategoryClientTest.php
tests/Unit/Infrastructure/Shopwired/Clients/CustomerClientTest.php
tests/Unit/Infrastructure/Linnworks/Clients/InventoryClientTest.php
tests/Unit/Infrastructure/Linnworks/Clients/ConnectivityClientTest.php
```

### 1.3 Transport/Session Tests (8 files)

**Strategy violation:** "Trust the framework" for retry logic, HTTP details.

```
tests/Unit/Infrastructure/Linnworks/LinnworksHttpTransportTest.php
tests/Unit/Infrastructure/Shopwired/ShopwiredHttpTransportTest.php
tests/Unit/Infrastructure/GoogleAds/GoogleAdsTransportTest.php
tests/Unit/Infrastructure/HelpScout/HelpScoutHttpTransportTest.php
tests/Unit/Infrastructure/Linnworks/LinnworksSessionTest.php
tests/Unit/Infrastructure/Linnworks/LinnworksSessionManagerTest.php
tests/Unit/Infrastructure/BingAds/BingAdsSessionTest.php
tests/Unit/Infrastructure/BingAds/BingAdsSessionManagerTest.php
```

### 1.4 Application Enum Tests (2 files)

**Strategy violation:** PHPStan validates types; enums without behavior don't need tests.

```
tests/Unit/Application/HelpScout/Queries/Conversation/Enums/SortFieldTest.php
tests/Unit/Application/HelpScout/Queries/Conversation/Enums/SortOrderTest.php
```

---

## Phase 2: Config Tests (7 files)

**Strategy rationale:** Config is boot-time validation. Invalid config fails fast at startup.

```
tests/Unit/Infrastructure/HelpScout/HelpScoutConfigTest.php
tests/Unit/Infrastructure/Shopwired/ShopwiredConfigTest.php
tests/Unit/Infrastructure/Linnworks/LinnworksConfigTest.php
tests/Unit/Infrastructure/GoogleAds/GoogleAdsConfigTest.php
tests/Unit/Infrastructure/ReviewsIo/ReviewsIoConfigTest.php
tests/Unit/Infrastructure/Mixpanel/MixpanelConfigTest.php
tests/Unit/Infrastructure/BingAds/BingAdsConfigTest.php
```

---

## Phase 3: Application Caching Services (2 files) - REDUCE TO ~5 TESTS EACH

**Strategy rationale:** Pure delegation wrappers test mock setup, not business logic.

**BUT:** TTL values and cache invalidation ARE business decisions worth testing.

**Files to reduce (not delete):**
```
tests/Unit/Application/HelpScout/Services/CachingHelpScoutServiceTest.php (22 tests → ~5)
tests/Unit/Application/Shopwired/Services/CachingShopwiredServiceTest.php (18 tests → ~5)
```

**Tests to KEEP:**
- Happy path caching (one per major method)
- TTL value verification (e.g., "agent profile cached for 7 days")
- Cache invalidation behavior

**Tests to REMOVE:**
- Cache key pattern assertions (implementation detail)
- Mock expectation verifications ("expects 'remember' to be called with...")
- Delegation verification ("calls underlying client")

---

## Phase 3.5: Domain Exception Tests (4 files) - REDUCE

**Strategy says:** "Exception classes - Data containers. Nothing to test."

**BUT:** These exceptions have message formatting logic worth testing.

**Action:** Reduce each file to 1 test (message format) - remove inheritance/null/previous exception tests.

```
tests/Unit/Domain/CustomerService/Exceptions/CustomerServiceAgentNotFoundExceptionTest.php → Keep message test only
tests/Unit/Domain/Exceptions/ConfigurationNotFoundExceptionTest.php → Keep message test only
tests/Unit/Domain/Exceptions/DatabaseOperationFailedExceptionTest.php → Keep message test only
tests/Unit/Domain/Exceptions/DuplicateRecordExceptionTest.php → Keep message test only
```

**Tests to remove from each file:**
- `it_extends_domain_exception` - PHPStan catches this
- `it_supports_previous_exception` - Standard PHP behavior
- `it_allows_null_previous_exception` - Standard PHP behavior

**Tests to keep:**
- `it_formats_message_with_*` - Actual business logic

---

## Phase 4: Query Params & Utilities (9 files)

**Strategy rationale:** "Don't test parameter construction in isolation" for Infrastructure.

```
tests/Unit/Infrastructure/Shopwired/ShopwiredQueryParamsTest.php
tests/Unit/Infrastructure/Shopwired/CustomerQueryParamsTest.php
tests/Unit/Infrastructure/Shopwired/OrderQueryParamsTest.php
tests/Unit/Infrastructure/Shopwired/ShopwiredPaginatorTest.php
tests/Unit/Infrastructure/Shopwired/RetryStrategyTest.php
tests/Unit/Infrastructure/Shopwired/Requests/OrderStatusUpdateOptionsTest.php
tests/Unit/Infrastructure/Shopwired/Enums/PaymentMethodRawTest.php
tests/Unit/Infrastructure/Linnworks/Support/LinnworksResponseParserTraitTest.php
tests/Unit/Infrastructure/Linnworks/Support/PascalCaseMapperTest.php
```

**Note:** Removing both `RetryStrategyTest.php` (Shopwired) and keeping `ApiRetryStrategyTest.php` (Support) because Support utilities are shared across multiple integrations and have transformation logic worth testing.

---

## Phase 5: Misc Infrastructure (3-5 files)

**Review individually using these criteria:**

| Keep if... | Remove if... |
|------------|--------------|
| Tests calculations/transformations | Tests mock expectations only |
| Tests error handling paths | Tests parameter construction |
| No Feature test covers the same boundary | Feature test already exists |

```
tests/Unit/Infrastructure/HelpScout/HelpScoutResponseParserTest.php → REMOVE (parsing internals)
tests/Unit/Infrastructure/Mixpanel/DTOs/MixpanelAdSpendEventDTOTest.php → REMOVE (DTO)
tests/Unit/Infrastructure/BingAds/BingAdsClientFactoryTest.php → REMOVE (factory)
tests/Unit/Infrastructure/BingAds/BingAdsClientTest.php → REVIEW during execution
tests/Unit/Infrastructure/GoogleAds/GoogleAdsClientTest.php → REVIEW during execution
```

**Files explicitly KEPT (not listed for removal):**
- `tests/Unit/Infrastructure/Supabase/SupabaseClientTest.php` → Tests client behavior not covered by RlsConnectionTest
- `tests/Unit/Providers/AppServiceProviderTest.php` → Security-critical production validation

---

## Tests to KEEP

### Domain Layer (35 files) - Keep All Value Object/Entity Tests
- All tests in `tests/Unit/Domain/` except the 4 Exception tests
- These test business logic that PHPStan cannot verify

### Application Layer (10 files) - Keep Business Logic Tests
- UseCase tests with branching logic
- ConversationSorter, MailboxEnrichmentService
- GracefulCache, EmailAliasResolver
- ConversationQueryParams

### Feature/Integration Tests (17 files) - Keep All
- Security middleware tests
- Job retry/exception handling tests
- API contract tests
- Integration tests at boundaries

### Infrastructure Keep List
```
tests/Unit/Infrastructure/BingAds/Transformers/BingAdsCsvTransformerTest.php
tests/Unit/Infrastructure/GoogleAds/Transformers/GoogleAdsRowTransformerTest.php
tests/Unit/Infrastructure/GoogleAds/Transformers/CampaignRowTransformerTest.php
tests/Unit/Infrastructure/Shopwired/Mappers/OrderLifecycleStatusMapperTest.php
tests/Unit/Infrastructure/Support/ApiRetryStrategyTest.php
tests/Unit/Infrastructure/Support/CsvFormatterTest.php
tests/Unit/Infrastructure/Support/LockableCacheTest.php
tests/Unit/Infrastructure/Support/RetryAfterParserTest.php
tests/Unit/Infrastructure/Storage/S3StorageClientTest.php
tests/Unit/Infrastructure/Supabase/EscalationsConfigRepositoryTest.php
tests/Unit/Infrastructure/Feeds/DoofinderFeedProcessorTest.php
tests/Unit/Infrastructure/Feeds/DoofinderItemTransformerTest.php
tests/Unit/Infrastructure/Mixpanel/LookupTables/CampaignLookupTableProviderTest.php
```

---

## Verification

### Before Removal (Establish Baseline)
```bash
make test-domain-coverage   # Record baseline: Domain at X%
make test-app-coverage      # Record baseline: Application at Y%
```

### After Each Phase
1. Run `make test` to ensure remaining tests pass
2. Run `make lint` to ensure no dead code references

### After All Phases Complete
```bash
make test-domain-coverage   # Verify still ≥90%
make test-app-coverage      # Verify still ≥70%
make check                  # Full validation
```

**If coverage drops below thresholds:** Review Phase 5 "Keep" list and restore tests for under-covered areas.

---

## Execution Order

1. **Establish baseline** - Run `make test-domain-coverage` and `make test-app-coverage`, record percentages
2. **Commit current state** (clean baseline before changes)
3. **Phase 1:** DELETE high-confidence files (37 files - DTOs, Clients, Transport, Enums)
4. **Run tests** to verify nothing breaks
5. **Phase 2:** DELETE Config tests (7 files)
6. **Phase 3:** REDUCE caching service tests (keep ~5 each, ~30 tests removed)
7. **Phase 3.5:** REDUCE Exception tests (keep message format tests only, ~12 tests removed)
8. **Phase 4:** DELETE Query Params/Utilities (9 files)
9. **Phase 5:** Review and DELETE Misc Infrastructure (3-5 files)
10. **Final verification:** `make check` + coverage comparison to baseline

**Rollback:** If issues arise, restore from commit made in step 2.
