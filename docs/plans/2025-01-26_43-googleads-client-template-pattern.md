# GoogleAds Integration Refactoring Plan

## Overview

Refactor GoogleAds infrastructure to follow the template pattern used by Mixpanel/ReviewsIo:
**Config (VO) → Transport (SDK wrapper) → Client (business logic) → Factory (wiring)**

## Target Architecture

```
GoogleAdsConfig (immutable VO - fail-fast validation)
    ↓
GoogleAdsTransport (SDK wrapper + exception translation)
    ↓
GoogleAdsClient (pure business logic - GAQL queries, transformations)
    ↓
GoogleAdsClientFactory (config creation, wiring)
```

## Key Design Decisions

### 1. Naming: `GoogleAdsTransport` (not `SdkWrapper`)
- Maintains naming consistency with `MixpanelHttpTransport`, `ReviewsIoHttpTransport`
- Pattern recognition across codebase more important than semantic precision
- Documents that this is "transport-like" even though SDK handles actual transport

### 2. SDK Building Location: Factory
- Factory owns OAuth2TokenBuilder and GoogleAdsClientBuilder instantiation
- Transport receives pre-built SDK client (dependency injection)
- Matches MixpanelClientFactory pattern

### 3. What Transport Does NOT Control
The Google Ads SDK handles these internally (we don't wrap):
- OAuth2 token refresh
- gRPC retry logic
- Connection pooling
- TLS negotiation

### 4. Exception Translation (in Transport)
- `ApiException` with `RESOURCE_EXHAUSTED` → `ExternalServiceUnavailableException` with retryAfter
- Other `ApiException` → `ExternalServiceUnavailableException` without retryAfter
- `ValidationException` → `InvalidApiRequestException` (new domain exception for malformed requests)
- Log before translation (warning for rate limits, error/critical for others)

### 5. New Domain Exception: `InvalidApiRequestException`
**File**: `app/Domain/Exceptions/InvalidApiRequestException.php`

Parallel to `InvalidApiResponseException` but for request-side validation failures:
- Indicates programming error (malformed GAQL, invalid parameters)
- Permanent failure - retrying won't help
- Jobs should `$this->fail()` immediately and alert developers
- Logged at CRITICAL level (code needs fixing)

---

## Implementation Steps

### Step 1: Create `GoogleAdsConfig`
**File**: `app/Infrastructure/GoogleAds/GoogleAdsConfig.php`

```php
final readonly class GoogleAdsConfig
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $refreshToken,
        public string $developerToken,
        public string $customerId,
        public ?string $loginCustomerId = null,
    ) {
        // RuntimeException for empty required strings
    }
}
```

**Validation**:
- All required credentials non-empty (`RuntimeException`)
- `loginCustomerId` optional (for MCC accounts)

### Step 2: Create `GoogleAdsTransport`
**File**: `app/Infrastructure/GoogleAds/GoogleAdsTransport.php`

```php
final readonly class GoogleAdsTransport
{
    private const string SERVICE_NAME = 'Google Ads';

    public function __construct(
        private SdkGoogleAdsClient $sdkClient,
        private GoogleAdsConfig $config,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws InvalidApiRequestException When request validation fails (programming error)
     */
    public function search(string $query): PagedListResponse
    {
        try {
            $request = $this->createSearchRequest($query);
            return $this->sdkClient->getGoogleAdsServiceClient()->search($request);
        } catch (ApiException $e) {
            throw $this->handleApiException($e);
        } catch (ValidationException $e) {
            throw $this->handleValidationException($e); // → InvalidApiRequestException
        }
    }
}
```

**Responsibilities**:
- Execute GAQL queries via SDK
- Translate ALL SDK exceptions to domain exceptions
- Parse `retry-after` from exception metadata on rate limits
- Log with appropriate level before translation

### Step 3: Refactor `GoogleAdsClient`
**File**: `app/Infrastructure/GoogleAds/GoogleAdsClient.php` (modify)

**Changes**:
- Replace `SdkGoogleAdsClient` + `$customerId` constructor with `GoogleAdsTransport`
- Remove `search()` and `createSearchRequest()` private methods
- Remove all exception handling (moved to transport)
- Keep only: GAQL query construction, response iteration, transformer delegation

**After**:
```php
final readonly class GoogleAdsClient implements GoogleAdsClientInterface
{
    public function __construct(
        private GoogleAdsTransport $transport,
    ) {}

    public function getDailyCampaignMetrics(string $date): array
    {
        $query = $this->buildMetricsQuery($date);
        $response = $this->transport->search($query);
        return $this->transformRows($response, GoogleAdsRowTransformer::toCampaignMetrics(...));
    }
}
```

### Step 4: Refactor `GoogleAdsClientFactory`
**File**: `app/Infrastructure/GoogleAds/GoogleAdsClientFactory.php` (modify)

**Changes**:
- Extract config creation to use `GoogleAdsConfig`
- Build SDK client in factory
- Wire full chain: Config → SDK → Transport → Client

```php
public static function create(): GoogleAdsClientInterface
{
    $config = self::createConfig();
    $sdkClient = self::buildSdkClient($config);
    $transport = new GoogleAdsTransport($sdkClient, $config);
    return new GoogleAdsClient($transport);
}
```

### Step 5: Delete Unused Exception
**Delete**: `app/Infrastructure/GoogleAds/Exceptions/GoogleAdsApiException.php`

This exception was never used - SDK exceptions are now translated in Transport.

### Step 6: Create Documentation
**File**: `app/Infrastructure/GoogleAds/CLAUDE.md`

Document:
- Template pattern adaptation for SDK-based client
- What we wrap vs what SDK handles internally
- Adding new query methods pattern

---

## Files Summary

### Create (3 files)
| File | Purpose |
|------|---------|
| `app/Domain/Exceptions/InvalidApiRequestException.php` | Domain exception for malformed requests |
| `app/Infrastructure/GoogleAds/GoogleAdsConfig.php` | Immutable config VO |
| `app/Infrastructure/GoogleAds/GoogleAdsTransport.php` | SDK wrapper + exception translation |

### Modify (2 files)
| File | Changes |
|------|---------|
| `app/Infrastructure/GoogleAds/GoogleAdsClient.php` | Use transport, remove SDK coupling |
| `app/Infrastructure/GoogleAds/GoogleAdsClientFactory.php` | Wire full chain |

### Delete (1 file)
| File | Reason |
|------|--------|
| `app/Infrastructure/GoogleAds/Exceptions/GoogleAdsApiException.php` | Unused |

### Create Documentation (1 file)
| File | Purpose |
|------|---------|
| `app/Infrastructure/GoogleAds/CLAUDE.md` | Architecture notes |

### Unchanged (4 files)
- `GoogleAdsClientInterface.php` - Contract unchanged
- `GoogleAdsRowTransformer.php` - Transformer unchanged
- `CampaignRowTransformer.php` - Transformer unchanged
- `InvalidGoogleAdsResponseException.php` - Used by transformers

---

## Testing Strategy

### New Test Files
| File | Focus |
|------|-------|
| `tests/Unit/Infrastructure/GoogleAds/GoogleAdsConfigTest.php` | Validation bounds, empty strings |
| `tests/Unit/Infrastructure/GoogleAds/GoogleAdsTransportTest.php` | Exception translation, logging |

### Refactored Test File
| File | Changes |
|------|---------|
| `tests/Unit/Infrastructure/AdSpend/GoogleAds/GoogleAdsClientTest.php` | Mock Transport instead of SDK |

### Mocking Strategy
- **Config**: No mocks (pure VO)
- **Transport**: Mock `SdkGoogleAdsClient`, verify exception translation
- **Client**: Mock `GoogleAdsTransport`, verify query construction + transformation

### Mutation-Resistant Assertions
- Use `assertSame()` not `assertEquals()` for exact values
- Assert exact exception messages and `retryAfter` values
- Verify logging with `->once()` for side effects
- Use callback assertions for query string content

---

## Implementation Order

1. `InvalidApiRequestException` (domain exception - no dependencies)
2. `GoogleAdsConfig` + `GoogleAdsConfigTest.php`
3. `GoogleAdsTransport` + `GoogleAdsTransportTest.php`
4. Refactor `GoogleAdsClientFactory`
5. Refactor `GoogleAdsClient` + update `GoogleAdsClientTest.php`
6. Delete `GoogleAdsApiException.php`
7. Create `CLAUDE.md`
8. Run `make lint` + `make test` + mutation testing

---

## Critical Files to Reference During Implementation

- `/Users/tom/code/IdeaProjects/alz-core/app/Domain/Exceptions/InvalidApiResponseException.php` - Pattern for new InvalidApiRequestException
- `/Users/tom/code/IdeaProjects/alz-core/app/Infrastructure/Mixpanel/MixpanelConfig.php` - Config validation pattern
- `/Users/tom/code/IdeaProjects/alz-core/app/Infrastructure/Mixpanel/MixpanelHttpTransport.php` - Exception handling structure
- `/Users/tom/code/IdeaProjects/alz-core/app/Infrastructure/GoogleAds/GoogleAdsClient.php` - Current implementation to refactor
- `/Users/tom/code/IdeaProjects/alz-core/app/Infrastructure/GoogleAds/GoogleAdsClientFactory.php` - Current factory to refactor
- `/Users/tom/code/IdeaProjects/alz-core/tests/Feature/Infrastructure/Mixpanel/MixpanelHttpTransportTest.php` - Transport test patterns
