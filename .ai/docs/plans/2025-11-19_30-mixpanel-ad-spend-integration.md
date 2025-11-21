# Mixpanel Ad Spend Integration Implementation Plan

**Goal**: Integrate Google Ads campaign spend data with Mixpanel analytics through daily synchronization job.

**Architecture**: Clean Architecture (Domain → Application → Infrastructure → Presentation) with PHPStan Level max compliance.

**Deployment**: Railway multi-service (web, worker, scheduler) with Laravel Horizon queue processing.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [File Structure](#file-structure)
3. [Implementation Phases](#implementation-phases)
4. [Layer-by-Layer Implementation](#layer-by-layer-implementation)
5. [Configuration & Secrets](#configuration--secrets)
6. [Testing Strategy](#testing-strategy)
7. [Deployment & Monitoring](#deployment--monitoring)
8. [Quality Checklist](#quality-checklist)

---

## Architecture Overview

### Clean Architecture Layers

```
┌─────────────────────────────────────────────────────────┐
│ Presentation Layer (Console Commands, HTTP if needed)  │
│ - Artisan commands for manual testing                  │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│ Application Layer (Use Cases, Jobs, Transformers)      │
│ - SyncAdSpendUseCase                                    │
│ - SyncGoogleAdsToMixpanelJob                           │
│ - AdSpendTransformer                                    │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│ Domain Layer (Interfaces, Value Objects, Exceptions)   │
│ - GoogleAdsClientInterface                              │
│ - MixpanelClientInterface                              │
│ - CampaignMetrics (Value Object)                       │
│ - AdSpendEvent (Value Object)                          │
│ - ApiRateLimitException                                 │
└─────────────────────────────────────────────────────────┘
                         ▲
                         │
┌─────────────────────────────────────────────────────────┐
│ Infrastructure Layer (API Clients, External Services)  │
│ - GoogleAdsClient (implements interface)                │
│ - MixpanelClient (implements interface)                │
└─────────────────────────────────────────────────────────┘
```

### Data Flow

```
Laravel Scheduler (8am UTC daily)
    │
    ▼
SyncGoogleAdsToMixpanelJob (dispatched to Horizon queue)
    │
    ├─→ GoogleAdsClient::getDailyCampaignMetrics(yesterday)
    │       │
    │       ├─→ Google Ads API (searchStream with GAQL)
    │       │
    │       └─→ GoogleAdsRowMapper::toCampaignMetrics()  ← VALIDATION BOUNDARY
    │           ├─ Validates null/missing fields
    │           ├─ Throws InvalidGoogleAdsResponseException on invalid data
    │           └─→ Returns validated CampaignMetrics (Domain)
    │
    ├─→ AdSpendTransformer::transformToMixpanelEvents()
    │       │
    │       └─→ Convert to AdSpendEvent objects, generate $insert_id
    │
    └─→ MixpanelClient::importBatch()
            │
            └─→ Mixpanel Import API (/import endpoint)
```

**Key Design Point**: GoogleAdsRowMapper acts as the Infrastructure/Domain boundary:
- **Left side** (Google Ads API): Untrusted external data with nullable fields
- **Right side** (CampaignMetrics): Validated domain objects ready for business logic
- **Validation**: Runtime exceptions (always active in production), not assertions (compile-out)

---

## File Structure

```
app/
├── Console/
│   └── Commands/
│       └── SyncAdSpendCommand.php          # Manual testing command
│
├── Domain/
│   ├── AdSpend/
│   │   ├── Contracts/
│   │   │   ├── GoogleAdsClientInterface.php
│   │   │   └── MixpanelClientInterface.php
│   │   ├── ValueObjects/
│   │   │   ├── CampaignMetrics.php
│   │   │   └── AdSpendEvent.php
│   │   └── Exceptions/
│   │       ├── ApiRateLimitException.php
│   │       ├── GoogleAdsApiException.php
│   │       ├── InvalidGoogleAdsResponseException.php  # Runtime validation at Infrastructure/Domain boundary
│   │       └── MixpanelApiException.php
│
├── Application/
│   └── AdSpend/
│       ├── Jobs/
│       │   └── SyncGoogleAdsToMixpanelJob.php
│       ├── UseCases/
│       │   └── SyncAdSpendUseCase.php
│       └── Services/
│           └── AdSpendTransformer.php
│
├── Infrastructure/
│   └── AdSpend/
│       ├── GoogleAds/
│       │   ├── GoogleAdsClient.php
│       │   ├── GoogleAdsClientFactory.php
│       │   └── GoogleAdsRowMapper.php       # Validation boundary - transforms & validates API responses
│       └── Mixpanel/
│           └── MixpanelClient.php
│
└── Providers/
    └── AdSpendServiceProvider.php

config/
├── google-ads.php                          # Google Ads API config
└── mixpanel.php                            # Mixpanel config

routes/
└── console.php                             # Schedule definition

tests/
├── Unit/
│   ├── Domain/
│   │   └── AdSpend/
│   │       └── ValueObjects/
│   │           ├── CampaignMetricsTest.php
│   │           └── AdSpendEventTest.php
│   └── Application/
│       └── AdSpend/
│           └── Services/
│               └── AdSpendTransformerTest.php
│
└── Feature/
    └── AdSpend/
        ├── SyncAdSpendUseCaseTest.php
        └── SyncGoogleAdsToMixpanelJobTest.php

phparkitect.php                             # Updated with AdSpend namespace rules
```

---

## Implementation Phases

### Phase 1: Domain Layer (Pure Business Logic) ✅ COMPLETE
**Duration**: 2-3 hours
**Dependencies**: None
**Status**: Completed on 2025-11-22

- [x] Create domain contracts (interfaces)
- [x] Define value objects with validation
- [x] Create custom exceptions
- [x] Write unit tests for value objects
- [x] Run PHPStan to ensure Level max compliance

**Acceptance Criteria**: ✅ All Met
- All interfaces use strict types ✅
- Value objects are readonly ✅
- Webmozart assertions validate constructor inputs ✅
- PHPStan reports zero errors ✅
- All quality gates passing (Pint, PHPStan, PHP Insights, PHPArkitect) ✅
- 128 tests passing with 292 assertions ✅

**Architectural Achievement**:
Implemented three-tier validation approach with GoogleAdsRowMapper at Infrastructure/Domain boundary, keeping Domain layer pure while ensuring production-safe validation of external API data.

---

### Phase 2: Infrastructure Layer (External API Integration)
**Duration**: 4-5 hours  
**Dependencies**: Phase 1 complete, Google Ads & Mixpanel accounts configured

- [ ] Install `googleads/google-ads-php` via Composer
- [ ] Create GoogleAdsClient implementation
- [ ] Create MixpanelClient implementation
- [ ] Create factory for GoogleAdsClient (OAuth setup)
- [ ] Add configuration files
- [ ] Write integration tests with Http::fake()
- [ ] Test error handling (rate limits, network failures)

**Acceptance Criteria**:
- Clients implement domain interfaces
- HTTP client uses Laravel's Http facade
- Proper exception mapping (API errors → domain exceptions)
- Rate limiting handled with exponential backoff
- All tests pass with mocked responses

---

### Phase 3: Application Layer (Use Cases & Transformation)
**Duration**: 3-4 hours  
**Dependencies**: Phase 1 & 2 complete

- [ ] Create AdSpendTransformer service
- [ ] Create SyncAdSpendUseCase
- [ ] Create SyncGoogleAdsToMixpanelJob
- [ ] Configure job middleware (throttling, overlapping)
- [ ] Write feature tests
- [ ] Test transformation logic (micros → pounds, timestamps)

**Acceptance Criteria**:
- Transformation handles edge cases (null conversions, zero spend)
- Job implements ShouldQueue correctly
- Middleware prevents overlapping runs
- Retry logic with exponential backoff
- Failed job handler logs to monitoring

---

### Phase 4: Presentation Layer & Scheduling
**Duration**: 1-2 hours  
**Dependencies**: Phase 3 complete

- [ ] Create Artisan command for manual testing
- [ ] Add schedule definition in routes/console.php
- [ ] Configure Horizon queue settings
- [ ] Test schedule with `php artisan schedule:test`
- [ ] Verify onOneServer() behavior

**Acceptance Criteria**:
- Command accepts --date parameter for historical syncs
- Schedule runs daily at 8am UTC
- Horizon dashboard shows job processing
- No duplicate runs across multiple Railway instances

---

### Phase 5: Service Provider & Dependency Injection
**Duration**: 1 hour  
**Dependencies**: All layers complete

- [ ] Create AdSpendServiceProvider
- [ ] Bind interfaces to implementations
- [ ] Register provider in bootstrap/providers.php
- [ ] Test DI container resolves correctly

**Acceptance Criteria**:
- `app(GoogleAdsClientInterface::class)` resolves correctly
- OAuth credentials loaded from config
- Singleton pattern for API clients

---

### Phase 6: Testing & Quality Assurance
**Duration**: 2-3 hours  
**Dependencies**: All code complete

- [ ] Run full test suite (`make test`)
- [ ] Run PHPStan (`make analyse`)
- [ ] Run Pint (`make pint-test`)
- [ ] Run PHP Insights (`make insights`)
- [ ] Run PHPArkitect (`make phparkitect`)
- [ ] Test with production-like data (Google Ads test account)
- [ ] Verify Mixpanel events appear correctly

**Acceptance Criteria**:
- All tests pass (80%+ coverage)
- PHPStan Level max: zero errors
- Pint: zero violations
- PHP Insights: all thresholds met
- PHPArkitect: architecture rules enforced

---

### Phase 7: Deployment & Monitoring
**Duration**: 2 hours  
**Dependencies**: All quality checks pass

- [ ] Add Railway environment variables
- [ ] Deploy to Railway staging
- [ ] Test cron trigger from Railway scheduler service
- [ ] Monitor Horizon dashboard for job execution
- [ ] Verify Mixpanel receives events
- [ ] Set up error alerting (email/Slack)
- [ ] Deploy to production

**Acceptance Criteria**:
- Job runs automatically on schedule
- Horizon shows successful completions
- Failed jobs trigger alerts
- No production errors for 7 days

---

## Validation Strategy: Three-Tier Approach

### Problem Statement
The Google Ads API is **loosely-typed** with protobuf-generated classes where all getters can return `null`:
```php
$campaign = $row->getCampaign();  // Can be null
$metrics = $row->getMetrics();    // Can be null
$campaignId = $campaign?->getId(); // Still can be null
```

If we put validation in Domain layer, we either:
1. Use **assertions** → compile-out in production → API nulls crash production
2. Use **exceptions** → pollutes Domain with infrastructure concerns

### Solution: Validation at Infrastructure/Domain Boundary

**Three Layers**:

| Layer | Responsibility | Tool |
|-------|---|---|
| **Domain** | Business logic (CampaignMetrics) | Webmozart Assertions (internal contracts, compile-out) |
| **Infrastructure** | API response validation | **GoogleAdsRowMapper** with runtime exceptions |
| **Infrastructure** | Error handling | InvalidGoogleAdsResponseException |

**Why This Works**:
1. **Domain stays pure**: CampaignMetrics only validates its own invariants
2. **Assertions compile-out**: No performance penalty in production
3. **Validation always runs**: RuntimeExceptions at boundary catch all API nulls
4. **Clear responsibility**: Mapper handles "untrusted external data"

**Example Flow**:
```
Google Ads API (nullable getters)
    ↓
GoogleAdsRowMapper::toCampaignMetrics()  ← VALIDATION BARRIER
    ↓ (all fields validated, no nulls possible)
CampaignMetrics (trusted domain object)
    ↓ (assertions validate internal contracts)
Business Logic (Application layer)
```

### Production Safety Guarantee
- If Google Ads API returns unexpected `null` → mapper catches it
- If mapper somehow passes invalid data → domain assertions catch it (dev error)
- Exception logged with context → job retries with backoff
- No silent failures, no corrupted data

---

## Layer-by-Layer Implementation

### Domain Layer

#### 1. GoogleAdsClientInterface
**File**: `app/Domain/AdSpend/Contracts/GoogleAdsClientInterface.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Contracts;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;

interface GoogleAdsClientInterface
{
    /**
     * Fetch daily campaign metrics for a specific date.
     *
     * @return array<int, CampaignMetrics>
     * @throws \App\Domain\AdSpend\Exceptions\GoogleAdsApiException
     * @throws \App\Domain\AdSpend\Exceptions\ApiRateLimitException
     */
    public function getDailyCampaignMetrics(string $date): array;
}
```

**PHPStan Notes**:
- Use `array<int, CampaignMetrics>` for typed array return
- Document all exceptions with `@throws`
- Interface lives in Domain (no Laravel dependencies)

---

#### 2. MixpanelClientInterface
**File**: `app/Domain/AdSpend/Contracts/MixpanelClientInterface.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Contracts;

use App\Domain\AdSpend\ValueObjects\AdSpendEvent;

interface MixpanelClientInterface
{
    /**
     * Import batch of ad spend events to Mixpanel.
     *
     * @param array<int, AdSpendEvent> $events
     * @return void
     * @throws \App\Domain\AdSpend\Exceptions\MixpanelApiException
     * @throws \App\Domain\AdSpend\Exceptions\ApiRateLimitException
     */
    public function importBatch(array $events): void;
}
```

---

#### 3. CampaignMetrics Value Object
**File**: `app/Domain/AdSpend/ValueObjects/CampaignMetrics.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class CampaignMetrics
{
    public function __construct(
        public int $campaignId,
        public string $campaignName,
        public string $date,
        public float $costInpounds,
        public int $clicks,
        public int $impressions,
        public float $conversions,
    ) {
        Assert::greaterThan($campaignId, 0, 'Campaign ID must be positive');
        Assert::notEmpty($campaignName, 'Campaign name cannot be empty');
        Assert::regex($date, '/^\d{4}-\d{2}-\d{2}$/', 'Date must be YYYY-MM-DD format');
        Assert::greaterThanEq($costInpounds, 0, 'Cost cannot be negative');
        Assert::greaterThanEq($clicks, 0, 'Clicks cannot be negative');
        Assert::greaterThanEq($impressions, 0, 'Impressions cannot be negative');
        Assert::greaterThanEq($conversions, 0, 'Conversions cannot be negative');
    }
}
```

**Key Points**:
- `readonly` prevents mutation after construction
- Webmozart assertions validate all **internal** contracts (compile-out in production)
- Pure domain object with no external dependencies
- Validation of external API data happens at Infrastructure boundary (see GoogleAdsRowMapper)

---

#### 4. AdSpendEvent Value Object
**File**: `app/Domain/AdSpend/ValueObjects/AdSpendEvent.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class AdSpendEvent
{
    public function __construct(
        public string $insertId,
        public int $timestamp,
        public string $source,
        public int $campaignId,
        public string $campaignName,
        public float $cost,
        public int $clicks,
        public int $impressions,
        public float $conversions,
        public string $utmSource,
        public string $utmMedium,
        public string $utmCampaign,
    ) {
        Assert::notEmpty($insertId, 'Insert ID cannot be empty');
        Assert::lessThanEq(strlen($insertId), 36, 'Insert ID must be ≤36 bytes');
        Assert::greaterThan($timestamp, 0, 'Timestamp must be positive Unix time');
        Assert::notEmpty($source, 'Source cannot be empty');
    }

    /**
     * Convert to Mixpanel API format.
     *
     * @return array<string, mixed>
     */
    public function toMixpanelFormat(): array
    {
        return [
            'event' => 'Ad Data',
            'properties' => [
                'time' => $this->timestamp,
                'distinct_id' => '',
                '$insert_id' => $this->insertId,
                'source' => $this->source,
                'campaign_id' => $this->campaignId,
                'campaign_name' => $this->campaignName,
                'cost' => $this->cost,
                'clicks' => $this->clicks,
                'impressions' => $this->impressions,
                'conversions' => $this->conversions,
                'utm_source' => $this->utmSource,
                'utm_medium' => $this->utmMedium,
                'utm_campaign' => $this->utmCampaign,
            ],
        ];
    }
}
```

---

#### 5. Custom Exceptions
**File**: `app/Domain/AdSpend/Exceptions/ApiRateLimitException.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class ApiRateLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter = 60,
    ) {
        parent::__construct($message);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
```

**File**: `app/Domain/AdSpend/Exceptions/GoogleAdsApiException.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class GoogleAdsApiException extends RuntimeException
{
    public static function fromApiError(string $errorCode, string $message): self
    {
        return new self("Google Ads API error [{$errorCode}]: {$message}");
    }
}
```

**File**: `app/Domain/AdSpend/Exceptions/InvalidGoogleAdsResponseException.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class InvalidGoogleAdsResponseException extends RuntimeException
{
    /**
     * Thrown when Google Ads API response contains null/missing required fields.
     * This is a **runtime validation exception** (always active in production),
     * not an assertion (which compile-out).
     *
     * Distinguishes from GoogleAdsApiException:
     * - GoogleAdsApiException: API returned error status code
     * - InvalidGoogleAdsResponseException: API returned 200 but data invalid
     */
    public static function missingField(string $field, string $context = ''): self
    {
        $message = "Google Ads response missing required field: {$field}";
        if ($context !== '') {
            $message .= " ({$context})";
        }
        return new self($message);
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return new self("Google Ads response has invalid value for {$field}: {$reason}");
    }
}
```

**File**: `app/Domain/AdSpend/Exceptions/MixpanelApiException.php`

```php
<?php

declare(strict_types=1);

namespace App\Domain\AdSpend\Exceptions;

use RuntimeException;

final class MixpanelApiException extends RuntimeException
{
    /**
     * @param array<int, array<string, mixed>> $failedRecords
     */
    public static function fromValidationErrors(array $failedRecords): self
    {
        $count = count($failedRecords);
        return new self("Mixpanel validation failed for {$count} events");
    }
}
```

**Key Points on Exception Strategy**:
- **ApiRateLimitException**: Recoverable → job retries with backoff
- **GoogleAdsApiException**: API error status → log and fail
- **InvalidGoogleAdsResponseException**: Invalid data despite 200 status → log and fail (production-safe validation)
- **MixpanelApiException**: Mixpanel validation failed → log failed records

---

### Infrastructure Layer

#### 6. GoogleAdsRowMapper (Validation Boundary)
**File**: `app/Infrastructure/AdSpend/GoogleAds/GoogleAdsRowMapper.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\GoogleAds;

use App\Domain\AdSpend\Exceptions\InvalidGoogleAdsResponseException;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;

/**
 * Validates and transforms Google Ads API responses into domain value objects.
 *
 * **Critical Role**: This class sits at the Infrastructure/Domain boundary and ensures:
 * 1. All null/missing fields are caught BEFORE domain layer
 * 2. Data validation is production-safe (uses exceptions, not assertions)
 * 3. Type conversions (micros → pounds) are explicit and validated
 *
 * **Design Pattern**: Mapper transforms external data with runtime validation.
 *
 * **Exception Strategy**:
 * - Assertions in Domain layer compile-out in production
 * - Validation in Infrastructure layer always runs in production
 * - This ensures external data safety at all times
 */
final class GoogleAdsRowMapper
{
    /**
     * @throws InvalidGoogleAdsResponseException
     */
    public static function toCampaignMetrics(GoogleAdsRow $row): CampaignMetrics
    {
        // Validate nested objects exist
        $campaign = $row->getCampaign();
        if ($campaign === null) {
            throw InvalidGoogleAdsResponseException::missingField('campaign', 'row.campaign');
        }

        $metrics = $row->getMetrics();
        if ($metrics === null) {
            throw InvalidGoogleAdsResponseException::missingField('metrics', 'row.metrics');
        }

        $segments = $row->getSegments();
        if ($segments === null) {
            throw InvalidGoogleAdsResponseException::missingField('segments', 'row.segments');
        }

        // Validate required fields
        $campaignId = $campaign->getId();
        if ($campaignId === null) {
            throw InvalidGoogleAdsResponseException::missingField('id', 'campaign.id');
        }

        $campaignName = $campaign->getName();
        if ($campaignName === null) {
            throw InvalidGoogleAdsResponseException::missingField('name', 'campaign.name');
        }

        $date = $segments->getDate();
        if ($date === null) {
            throw InvalidGoogleAdsResponseException::missingField('date', 'segments.date');
        }

        $costMicros = $metrics->getCostMicros();
        if ($costMicros === null) {
            throw InvalidGoogleAdsResponseException::missingField('cost_micros', 'metrics.cost_micros');
        }

        $clicks = $metrics->getClicks();
        if ($clicks === null) {
            throw InvalidGoogleAdsResponseException::missingField('clicks', 'metrics.clicks');
        }

        $impressions = $metrics->getImpressions();
        if ($impressions === null) {
            throw InvalidGoogleAdsResponseException::missingField('impressions', 'metrics.impressions');
        }

        $conversions = $metrics->getConversions();
        if ($conversions === null) {
            throw InvalidGoogleAdsResponseException::missingField('conversions', 'metrics.conversions');
        }

        // Create domain value object with validated data
        return new CampaignMetrics(
            campaignId: (int) $campaignId,
            campaignName: $campaignName,
            date: $date,
            costInpounds: $costMicros / 1_000_000,
            clicks: (int) $clicks,
            impressions: (int) $impressions,
            conversions: (float) $conversions,
        );
    }
}
```

**Why This Approach**:
1. **Assertions vs Validation**:
   - Domain uses Webmozart assertions (compile-out in production)
   - Infrastructure validates external data with exceptions (always active)

2. **Separation of Concerns**:
   - Domain: "Assume data is valid, use assertions for programmer errors"
   - Infrastructure: "Validate untrusted external data with exceptions"

3. **Production Safety**:
   - If Google Ads API returns unexpected null, Infrastructure catches it immediately
   - Error logged and job retries (no silent failures)
   - Assertions in domain don't protect against null values from API

---

#### 7. GoogleAdsClient Implementation
**File**: `app/Infrastructure/AdSpend/GoogleAds/GoogleAdsClient.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\GoogleAds;

use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\InvalidGoogleAdsResponseException;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkClient;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsStreamRequest;
use Google\ApiCore\ApiException;
use Illuminate\Support\Facades\Log;

final class GoogleAdsClient implements GoogleAdsClientInterface
{
    public function __construct(
        private readonly SdkClient $client,
        private readonly string $customerId,
    ) {}

    /**
     * @return array<int, CampaignMetrics>
     * @throws InvalidGoogleAdsResponseException
     */
    public function getDailyCampaignMetrics(string $date): array
    {
        $query = $this->buildQuery($date);

        try {
            Log::info('Fetching Google Ads data', [
                'date' => $date,
                'customer_id' => $this->customerId,
            ]);

            $service = $this->client->getGoogleAdsServiceClient();
            $stream = $service->searchStream(
                SearchGoogleAdsStreamRequest::build($this->customerId, $query)
            );

            $metrics = [];
            foreach ($stream->iterateAllElements() as $row) {
                // Use mapper at Infrastructure boundary to validate before creating domain objects
                $metrics[] = GoogleAdsRowMapper::toCampaignMetrics($row);
            }

            Log::info('Google Ads data fetched', [
                'campaigns' => count($metrics),
            ]);

            return $metrics;

        } catch (InvalidGoogleAdsResponseException $e) {
            // Re-throw validation errors (already production-safe)
            Log::error('Invalid Google Ads response', [
                'error' => $e->getMessage(),
            ]);
            throw $e;

        } catch (ApiException $e) {
            if ($this->isRateLimitError($e)) {
                throw new ApiRateLimitException(
                    'Google Ads API rate limit exceeded',
                    retryAfter: 60
                );
            }

            throw GoogleAdsApiException::fromApiError(
                $e->getStatus(),
                $e->getMessage()
            );
        }
    }

    private function buildQuery(string $date): string
    {
        return sprintf(
            "SELECT campaign.id, campaign.name, segments.date, 
             metrics.cost_micros, metrics.clicks, metrics.impressions, 
             metrics.conversions 
             FROM campaign 
             WHERE segments.date = '%s' 
             AND campaign.status = 'ENABLED'
             ORDER BY campaign.id",
            $date
        );
    }

    private function isRateLimitError(ApiException $e): bool
    {
        return str_contains($e->getMessage(), 'RESOURCE_TEMPORARILY_EXHAUSTED')
            || str_contains($e->getMessage(), 'RATE_LIMIT_EXCEEDED');
    }
}
```

---

#### 8. GoogleAdsClientFactory
**File**: `app/Infrastructure/AdSpend/GoogleAds/GoogleAdsClientFactory.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\GoogleAds;

use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkClient;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use RuntimeException;

final class GoogleAdsClientFactory
{
    public static function create(): GoogleAdsClient
    {
        $clientId = config('google-ads.client_id');
        $clientSecret = config('google-ads.client_secret');
        $refreshToken = config('google-ads.refresh_token');
        $developerToken = config('google-ads.developer_token');
        $customerId = config('google-ads.customer_id');

        if (!is_string($clientId) || $clientId === '') {
            throw new RuntimeException('GOOGLE_ADS_CLIENT_ID not configured');
        }
        if (!is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('GOOGLE_ADS_CLIENT_SECRET not configured');
        }
        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new RuntimeException('GOOGLE_ADS_REFRESH_TOKEN not configured');
        }
        if (!is_string($developerToken) || $developerToken === '') {
            throw new RuntimeException('GOOGLE_ADS_DEVELOPER_TOKEN not configured');
        }
        if (!is_string($customerId) || $customerId === '') {
            throw new RuntimeException('GOOGLE_ADS_CUSTOMER_ID not configured');
        }

        $oauth = (new OAuth2TokenBuilder())
            ->withClientId($clientId)
            ->withClientSecret($clientSecret)
            ->withRefreshToken($refreshToken)
            ->build();

        $sdkClient = (new GoogleAdsClientBuilder())
            ->withOAuth2Credential($oauth)
            ->withDeveloperToken($developerToken)
            ->build();

        return new GoogleAdsClient($sdkClient, $customerId);
    }
}
```

**PHPStan Notes**:
- Explicit type checks ensure `config()` returns string
- RuntimeException for missing config (fail fast)
- Factory pattern keeps OAuth complexity out of service provider

---

#### 9. MixpanelClient Implementation
**File**: `app/Infrastructure/AdSpend/Mixpanel/MixpanelClient.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\AdSpend\Mixpanel;

use App\Domain\AdSpend\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\AdSpendEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MixpanelClient implements MixpanelClientInterface
{
    private const IMPORT_ENDPOINT = '/import';
    private const BATCH_SIZE = 2000;

    public function __construct(
        private readonly string $projectToken,
        private readonly string $baseUrl = 'https://api.mixpanel.com',
    ) {}

    /**
     * @param array<int, AdSpendEvent> $events
     */
    public function importBatch(array $events): void
    {
        $batches = array_chunk($events, self::BATCH_SIZE);

        foreach ($batches as $index => $batch) {
            $this->importSingleBatch($batch, $index + 1, count($batches));
        }
    }

    /**
     * @param array<int, AdSpendEvent> $events
     */
    private function importSingleBatch(array $events, int $batchNum, int $totalBatches): void
    {
        $payload = array_map(
            static fn(AdSpendEvent $event): array => $event->toMixpanelFormat(),
            $events
        );

        Log::info('Importing to Mixpanel', [
            'batch' => "{$batchNum}/{$totalBatches}",
            'events' => count($events),
        ]);

        $response = $this->buildRequest()
            ->post($this->baseUrl . self::IMPORT_ENDPOINT . '?strict=1', $payload);

        if ($response->status() === 429) {
            throw new ApiRateLimitException(
                'Mixpanel API rate limit exceeded',
                retryAfter: 60
            );
        }

        if ($response->failed()) {
            $body = $response->json();

            if (isset($body['failed_records']) && is_array($body['failed_records'])) {
                Log::error('Mixpanel validation errors', [
                    'failed_count' => count($body['failed_records']),
                    'errors' => $body['failed_records'],
                ]);

                throw MixpanelApiException::fromValidationErrors($body['failed_records']);
            }

            throw new MixpanelApiException(
                "Mixpanel import failed: {$response->body()}"
            );
        }

        Log::info('Mixpanel import successful', [
            'imported' => $response->json('num_records_imported'),
        ]);
    }

    private function buildRequest(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(3, 100, static function ($exception): bool {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            })
            ->withBasicAuth($this->projectToken, '');
    }
}
```

**Key Points**:
- Batches events into groups of 2000 (Mixpanel limit)
- Uses `strict=1` for validation feedback
- Retries only network errors (not validation errors)
- HTTP Basic Auth with project token

---

### Application Layer

#### 10. AdSpendTransformer Service
**File**: `app/Application/AdSpend/Services/AdSpendTransformer.php`

```php
<?php

declare(strict_types=1);

namespace App\Application\AdSpend\Services;

use App\Domain\AdSpend\ValueObjects\AdSpendEvent;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;

final class AdSpendTransformer
{
    /**
     * Transform campaign metrics into Mixpanel events.
     *
     * @param array<int, CampaignMetrics> $campaigns
     * @return array<int, AdSpendEvent>
     */
    public function transformToEvents(array $campaigns): array
    {
        return array_map(
            fn(CampaignMetrics $campaign): AdSpendEvent => $this->transformSingle($campaign),
            $campaigns
        );
    }

    private function transformSingle(CampaignMetrics $campaign): AdSpendEvent
    {
        return new AdSpendEvent(
            insertId: $this->generateInsertId($campaign),
            timestamp: strtotime($campaign->date),
            source: 'Google',
            campaignId: $campaign->campaignId,
            campaignName: $campaign->campaignName,
            cost: $campaign->costInpounds,
            clicks: $campaign->clicks,
            impressions: $campaign->impressions,
            conversions: $campaign->conversions,
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: $this->sanitizeCampaignName($campaign->campaignName),
        );
    }

    private function generateInsertId(CampaignMetrics $campaign): string
    {
        $raw = "G-{$campaign->date}-{$campaign->campaignId}";

        // If too long, hash it
        if (strlen($raw) > 36) {
            return substr(md5($raw), 0, 36);
        }

        return $raw;
    }

    private function sanitizeCampaignName(string $name): string
    {
        // Remove special characters for UTM compatibility
        return strtolower(preg_replace('/[^a-zA-Z0-9-_]/', '_', $name) ?? $name);
    }
}
```

---

#### 11. SyncAdSpendUseCase
**File**: `app/Application/AdSpend/UseCases/SyncAdSpendUseCase.php`

```php
<?php

declare(strict_types=1);

namespace App\Application\AdSpend\UseCases;

use App\Application\AdSpend\Services\AdSpendTransformer;
use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Contracts\MixpanelClientInterface;
use Illuminate\Support\Facades\Log;

final class SyncAdSpendUseCase
{
    public function __construct(
        private readonly GoogleAdsClientInterface $googleAds,
        private readonly MixpanelClientInterface $mixpanel,
        private readonly AdSpendTransformer $transformer,
    ) {}

    public function execute(string $date): void
    {
        Log::info('Starting ad spend sync', ['date' => $date]);

        // Fetch from Google Ads
        $campaigns = $this->googleAds->getDailyCampaignMetrics($date);

        if ($campaigns === []) {
            Log::warning('No campaigns found', ['date' => $date]);
            return;
        }

        // Transform to Mixpanel format
        $events = $this->transformer->transformToEvents($campaigns);

        // Import to Mixpanel
        $this->mixpanel->importBatch($events);

        Log::info('Ad spend sync completed', [
            'date' => $date,
            'campaigns' => count($campaigns),
        ]);
    }
}
```

---

#### 12. SyncGoogleAdsToMixpanelJob
**File**: `app/Application/AdSpend/Jobs/SyncGoogleAdsToMixpanelJob.php`

```php
<?php

declare(strict_types=1);

namespace App\Application\AdSpend\Jobs;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncGoogleAdsToMixpanelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    public function __construct(
        private readonly ?string $date = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('google-ads-sync'))
                ->releaseAfter(60)
                ->expireAfter(3600),
        ];
    }

    public function handle(SyncAdSpendUseCase $useCase): void
    {
        $date = $this->date ?? now()->subDay()->format('Y-m-d');

        try {
            $useCase->execute($date);

        } catch (ApiRateLimitException $e) {
            Log::warning('Rate limit hit', [
                'retry_after' => $e->getRetryAfter(),
                'attempt' => $this->attempts(),
            ]);
            $this->release($e->getRetryAfter());

        } catch (Throwable $e) {
            Log::error('Ad spend sync failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Ad spend sync permanently failed', [
            'exception' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        // TODO: Send alert (email/Slack)
    }
}
```

---

### Presentation Layer

#### 13. Manual Testing Command
**File**: `app/Console/Commands/SyncAdSpendCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\AdSpend\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Console\Command;

final class SyncAdSpendCommand extends Command
{
    protected $signature = 'adspend:sync {--date= : Date in YYYY-MM-DD format (default: yesterday)}';

    protected $description = 'Manually sync Google Ads data to Mixpanel';

    public function handle(): int
    {
        $date = $this->option('date');

        if ($date !== null && !is_string($date)) {
            $this->error('Date must be a string');
            return self::FAILURE;
        }

        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Date must be in YYYY-MM-DD format');
            return self::FAILURE;
        }

        $this->info('Dispatching sync job...');

        SyncGoogleAdsToMixpanelJob::dispatch($date);

        $this->info('Job dispatched. Check Horizon for status.');

        return self::SUCCESS;
    }
}
```

---

### Configuration

#### 14. Google Ads Config
**File**: `config/google-ads.php`

```php
<?php

declare(strict_types=1);

return [
    'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
    'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
    'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
    'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN'),
    'customer_id' => env('GOOGLE_ADS_CUSTOMER_ID'),
    'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
];
```

#### 15. Mixpanel Config
**File**: `config/mixpanel.php`

```php
<?php

declare(strict_types=1);

return [
    'project_token' => env('MIXPANEL_PROJECT_TOKEN'),
    'base_url' => env('MIXPANEL_BASE_URL', 'https://api.mixpanel.com'),
];
```

#### 16. Schedule Definition
**File**: `routes/console.php`

```php
<?php

declare(strict_types=1);

use App\Application\AdSpend\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SyncGoogleAdsToMixpanelJob())
    ->dailyAt('08:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(10)
    ->emailOutputOnFailure('[email protected]');
```

---

### Service Provider

#### 17. AdSpendServiceProvider
**File**: `app/Providers/AdSpendServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Contracts\MixpanelClientInterface;
use App\Infrastructure\AdSpend\GoogleAds\GoogleAdsClientFactory;
use App\Infrastructure\AdSpend\Mixpanel\MixpanelClient;
use Illuminate\Support\ServiceProvider;

final class AdSpendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GoogleAdsClientInterface::class, static function (): GoogleAdsClientInterface {
            return GoogleAdsClientFactory::create();
        });

        $this->app->singleton(MixpanelClientInterface::class, static function (): MixpanelClientInterface {
            $projectToken = config('mixpanel.project_token');
            $baseUrl = config('mixpanel.base_url');

            if (!is_string($projectToken) || $projectToken === '') {
                throw new \RuntimeException('MIXPANEL_PROJECT_TOKEN not configured');
            }

            if (!is_string($baseUrl) || $baseUrl === '') {
                throw new \RuntimeException('MIXPANEL_BASE_URL not configured');
            }

            return new MixpanelClient($projectToken, $baseUrl);
        });
    }
}
```

**Register in** `bootstrap/providers.php`:
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\AdSpendServiceProvider::class, // Add this
    ...app()->environment('local') ? [App\Providers\TelescopeServiceProvider::class] : [],
];
```

---

## Configuration & Secrets

### Required Environment Variables

Add to `.env.example`:
```bash
# Google Ads API
GOOGLE_ADS_DEVELOPER_TOKEN=
GOOGLE_ADS_CLIENT_ID=
GOOGLE_ADS_CLIENT_SECRET=
GOOGLE_ADS_REFRESH_TOKEN=
GOOGLE_ADS_CUSTOMER_ID=
GOOGLE_ADS_LOGIN_CUSTOMER_ID=

# Mixpanel
MIXPANEL_PROJECT_TOKEN=
MIXPANEL_BASE_URL=https://api.mixpanel.com
```

### Railway Environment Setup

**In Railway Dashboard → Variables**:

1. **Web Service**:
   - Add all variables above
   
2. **Worker Service**:
   - Share variables from Web service
   
3. **Scheduler Service** (if separate):
   - Share variables from Web service

### Getting Credentials

#### Google Ads OAuth Token
```bash
# 1. Clone Google Ads PHP examples
git clone https://github.com/googleads/google-ads-php.git /tmp/google-ads

# 2. Run OAuth flow
cd /tmp/google-ads
composer install
php examples/Authentication/GenerateUserCredentials.php

# 3. Follow prompts to get refresh_token
# 4. Copy refresh_token to Railway env vars
```

#### Mixpanel Project Token
```
1. Go to Mixpanel → Project Settings → Access Keys
2. Copy "Project Token" (NOT the API Secret)
3. Add to Railway as MIXPANEL_PROJECT_TOKEN
```

---

## Testing Strategy

### Unit Tests

**Test Value Objects**:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AdSpend\ValueObjects;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CampaignMetricsTest extends TestCase
{
    #[Test]
    public function it_creates_valid_campaign_metrics(): void
    {
        $metrics = new CampaignMetrics(
            campaignId: 123456,
            campaignName: 'Test Campaign',
            date: '2024-11-18',
            costInpounds: 125.43,
            clicks: 342,
            impressions: 8234,
            conversions: 12.5,
        );

        $this->assertSame(123456, $metrics->campaignId);
        $this->assertSame(125.43, $metrics->costInpounds);
    }

    #[Test]
    public function it_rejects_negative_campaign_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CampaignMetrics(
            campaignId: -1,
            campaignName: 'Test',
            date: '2024-11-18',
            costInpounds: 0,
            clicks: 0,
            impressions: 0,
            conversions: 0,
        );
    }

    #[Test]
    public function it_rejects_invalid_date_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CampaignMetrics(
            campaignId: 123,
            campaignName: 'Test',
            date: '11/18/2024',
            costInpounds: 0,
            clicks: 0,
            impressions: 0,
            conversions: 0,
        );
    }
}
```

### Feature Tests

**Test Job with Mocked APIs**:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AdSpend;

use App\Application\AdSpend\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SyncGoogleAdsToMixpanelJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_syncs_successfully(): void
    {
        Http::fake([
            'googleads.googleapis.com/*' => Http::response([
                'results' => [
                    [
                        'campaign' => ['id' => '123', 'name' => 'Test'],
                        'metrics' => [
                            'cost_micros' => '5430000',
                            'clicks' => '145',
                            'impressions' => '2340',
                            'conversions' => '12',
                        ],
                        'segments' => ['date' => '2024-11-17'],
                    ],
                ],
            ], 200),
            
            'api.mixpanel.com/*' => Http::response([
                'code' => 200,
                'num_records_imported' => 1,
            ], 200),
        ]);

        $job = new SyncGoogleAdsToMixpanelJob('2024-11-17');
        $job->handle(app(\App\Application\AdSpend\UseCases\SyncAdSpendUseCase::class));

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_releases_job_on_rate_limit(): void
    {
        Queue::fake();

        Http::fake([
            'googleads.googleapis.com/*' => Http::response([
                'error' => ['code' => 'RESOURCE_TEMPORARILY_EXHAUSTED'],
            ], 429),
        ]);

        SyncGoogleAdsToMixpanelJob::dispatch('2024-11-17');

        Queue::assertPushed(SyncGoogleAdsToMixpanelJob::class);
    }
}
```

### PHPArkitect Rules

**Add to `phparkitect.php`**:
```php
// Ad Spend domain rules
$rules[] = Rule::allClasses()
    ->that(new ResideInOneOfTheseNamespaces('App\Domain\AdSpend'))
    ->should(new NotHaveDependencyOutsideNamespace('App\Domain\AdSpend', [
        'DateTime', 'DateTimeImmutable', 'Webmozart\Assert',
    ]))
    ->because('Domain layer must be self-contained');

$rules[] = Rule::allClasses()
    ->that(new ResideInOneOfTheseNamespaces('App\Application\AdSpend'))
    ->should(new NotHaveDependencyOutsideNamespace('App\Application\AdSpend', [
        'App\Domain\AdSpend', 'Illuminate', 'DateTime',
    ]))
    ->because('Application layer only depends on Domain');

$rules[] = Rule::allClasses()
    ->that(new ResideInOneOfTheseNamespaces('App\Infrastructure\AdSpend'))
    ->should(new HaveNameMatching('*Client|*Factory'))
    ->because('Infrastructure classes must end with Client or Factory');
```

---

## Deployment & Monitoring

### Railway Scheduler Service

**Option 1: Cron Runner Container**

Create new Railway service from existing repo:
- **Service Name**: Scheduler
- **Start Command**: `while true; do php artisan schedule:run; sleep 60; done`
- **Environment Variables**: Share from Web service

**Option 2: External Cron (cron-job.org)**

If Railway doesn't support separate scheduler easily:
1. Create webhook endpoint: `/api/webhooks/trigger-sync`
2. Add middleware for secret token authentication
3. Configure cron-job.org to hit endpoint daily

### Monitoring Checklist

- [ ] Horizon dashboard accessible at `/horizon`
- [ ] Job appears in "Completed Jobs" after manual dispatch
- [ ] Failed jobs trigger email alerts
- [ ] Mixpanel shows "Ad Data" events with correct properties
- [ ] Logs show successful sync in `storage/logs/laravel.log`

### Error Alert Setup

**Add to `failed()` method in Job**:
```php
use Illuminate\Support\Facades\Mail;

public function failed(?Throwable $exception): void
{
    Log::critical('Ad spend sync permanently failed', [
        'exception' => $exception?->getMessage(),
    ]);

    // Email alert
    Mail::to('[email protected]')
        ->send(new AdSpendSyncFailedMail($exception));
}
```

---

## Quality Checklist

### Before Committing

- [ ] `make lint` passes (Pint + PHPStan + PHPArkitect)
- [ ] `make test` passes with 80%+ coverage
- [ ] All value objects are `readonly`
- [ ] All methods have strict return types
- [ ] No `mixed` types in signatures
- [ ] All exceptions extend appropriate SPL exceptions
- [ ] Config values are type-checked
- [ ] No `@phpstan-ignore` comments

### Before Deploying

- [ ] `make lint-full` passes (includes PHP Insights)
- [ ] Railway environment variables set
- [ ] Google Ads test account verified
- [ ] Mixpanel test project verified
- [ ] Manual `artisan adspend:sync` works
- [ ] Horizon shows job processing
- [ ] Mixpanel receives events correctly
- [ ] Error alerts configured

---

## Estimated Timeline

**Total**: 15-18 hours

| Phase                | Duration | Blocker Risk             |
|----------------------|----------|--------------------------|
| Domain Layer         | 2-3h     | Low                      |
| Infrastructure Layer | 4-5h     | Medium (OAuth setup)     |
| Application Layer    | 3-4h     | Low                      |
| Presentation Layer   | 1-2h     | Low                      |
| Service Provider     | 1h       | Low                      |
| Testing & QA         | 2-3h     | Medium (API credentials) |
| Deployment           | 2h       | High (Railway cron)      |

**High-Risk Items**:
1. Getting Google Ads OAuth refresh token (1-2h setup)
2. Railway scheduler service setup (may need workaround)
3. Testing with real Google Ads data (API limits)

**Mitigation**:
- Start with OAuth setup in Phase 0
- Use Http::fake() for all development
- Deploy scheduler last (use manual command initially)

---

## Next Steps

1. **Install Dependencies**:
   ```bash
   composer require googleads/google-ads-php
   ```

2. **Create Google Ads OAuth Credentials**:
   - https://console.cloud.google.com/apis/credentials
   - Create OAuth 2.0 Client ID
   - Download credentials JSON
   - Run GenerateUserCredentials.php

3. **Start with Domain Layer**:
   ```bash
   mkdir -p app/Domain/AdSpend/{Contracts,ValueObjects,Exceptions}
   ```

4. **Follow phases sequentially** (each phase builds on previous)

5. **Test incrementally** (unit tests before integration tests)

**Ready to start?** Begin with Phase 1 (Domain Layer) and work through sequentially.
