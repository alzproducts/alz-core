# Mixpanel Client Refactoring: Template Pattern Implementation

## Overview

Refactor `MixpanelClient` to follow the established ReviewsIo template pattern, creating reusable architecture for future complex API integrations.

**Pattern**: `Config VO → HttpTransport → Client → Factory`

---

## File Structure

### New Files

| File                                            | Purpose                                                | ~Lines |
|-------------------------------------------------|--------------------------------------------------------|--------|
| `MixpanelConfig.php`                              | Immutable config VO with fail-fast validation          | ~100   |
| `MixpanelHttpTransport.php`                       | HTTP layer: auth, retry, exception translation         | ~130   |
| `CLAUDE.md`                                       | Documentation: transport design decisions, usage guide | ~60    |
| `tests/Unit/.../MixpanelConfigTest.php`           | Config boundary testing (pure unit)                    | ~200   |
| `tests/Feature/.../MixpanelHttpTransportTest.php` | Transport exception handling (uses Http::fake)         | ~150   |

### Modified Files

| File                      | Changes                                                  |
|---------------------------|----------------------------------------------------------|
| `MixpanelClient.php`        | Remove HTTP logic, delegate to transport (~190→60 lines) |
| `MixpanelClientFactory.php` | Wire Config → Transport → Client                         |
| `MixpanelClientTest.php`    | Update setUp() to use new constructor                    |
| `config/mixpanel.php`       | Add timeout/retry settings                               |

### Deleted Files

| File                                | Reason                 |
|-------------------------------------|------------------------|
| `Exceptions/MixpanelApiException.php` | Dead code (never used) |

---

## Component Design

### 1. MixpanelConfig (Value Object)

**Location**: `app/Infrastructure/Mixpanel/MixpanelConfig.php`

**Design Decisions**:
- Main API URL as constant (fixed Mixpanel endpoint for auth verification)
- Data API URL configurable (for testing)
- Single timeout for all operations (matches ReviewsIo pattern)
- Fail-fast validation in constructor

```php
final readonly class MixpanelConfig
{
    // Constants
    public const string MAIN_API_URL = 'https://mixpanel.com';
    public const string DEFAULT_DATA_API_URL = 'https://api.mixpanel.com';
    private const int MAX_TIMEOUT_SECONDS = 300;
    private const int MAX_RETRY_ATTEMPTS = 10;
    private const int MAX_RETRY_DELAY_MS = 5000;

    public function __construct(
        public string $dataApiBaseUrl,
        public string $serviceAccountUsername,
        public string $serviceAccountPassword,
        public string $projectId,
        public string $lookupTableId,
        public int $timeout = 30,
        public int $retryTimes = 3,
        public int $retryDelay = 100,
    ) {
        // RuntimeException: empty credentials
        // InvalidArgumentException: out-of-bounds numerics
    }
}
```

**Validation Rules**:
- Empty string → `RuntimeException` (dataApiBaseUrl, username, password, projectId, lookupTableId)
- Timeout bounds: 1-300 → `InvalidArgumentException`
- Retry times bounds: 0-10 → `InvalidArgumentException`
- Retry delay bounds: 0-5000ms → `InvalidArgumentException`

### 2. MixpanelHttpTransport

**Location**: `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php`

**Design Decisions**:
- Three specialized methods for different HTTP patterns (not one generic method)
- `verifyAuth()` for connectivity (no parameters - knows auth endpoint internally)
- `postJson()` for event imports (relative endpoint)
- `putCsv()` for lookup table replacement (relative endpoint)
- All methods use HTTP Basic Auth
- Exception translation matches ReviewsIoHttpTransport pattern

```php
final readonly class MixpanelHttpTransport
{
    private const string SERVICE_NAME = 'Mixpanel';
    private const string AUTH_ENDPOINT = '/api/app/me';

    public function __construct(
        private MixpanelConfig $config,
    ) {}

    /** Verify auth credentials against main API. No retry (fail-fast). */
    public function verifyAuth(): void;

    /** POST JSON with retry (event imports). */
    public function postJson(string $endpoint, array $payload): Response;

    /** PUT CSV with retry (lookup table). */
    public function putCsv(string $endpoint, string $csv): Response;

    // Private helpers (exact ReviewsIoHttpTransport pattern)
    private function createBaseRequest(): PendingRequest;
    private function handleRequestException(RequestException $e): ExternalServiceUnavailableException;
    private function handleConnectionException(ConnectionException $e): ExternalServiceUnavailableException;
}
```

**Why Three Methods?**
- Each operation has distinct characteristics (retry/no-retry, content-type)
- `verifyAuth()` encapsulates the auth endpoint URL internally (cleaner API)
- Type-safe: compiler enforces correct usage
- Self-documenting method names
- Template pattern for future clients with similar multi-operation needs

### 3. MixpanelClient (Refactored)

**Location**: `app/Infrastructure/Mixpanel/MixpanelClient.php`

**New Constructor**:
```php
public function __construct(
    private MixpanelHttpTransport $transport,
    private MixpanelConfig $config,
) {}
```

**Simplified Methods** (business logic only, no HTTP concerns):

```php
public function verifyConnectivity(): void
{
    $this->transport->verifyAuth();
}

public function importCampaigns(array $campaigns): void
{
    if (\count($campaigns) === 0) {
        return;
    }

    $payload = \array_map(
        static fn(CampaignMetrics $c) =>
            MixpanelAdSpendEventDTO::fromCampaignMetrics($c)->toMixpanelFormat(),
        $campaigns,
    );

    $this->transport->postJson(
        "/import?project_id={$this->config->projectId}",
        $payload
    );
}

public function replaceCampaignLookupTable(array $campaigns): void
{
    $headers = ['utm_campaign', 'campaign_name', 'campaign_status'];
    $rows = \array_map(/* ... */);
    $csv = CsvFormatter::format($headers, $rows);

    $this->transport->putCsv(
        "/lookup_tables/{$this->config->projectId}/{$this->config->lookupTableId}",
        $csv
    );
}
```

**Removed from Client**:
- `Http` facade usage
- `Log` facade usage
- `RequestException`/`ConnectionException` handling
- Retry configuration
- Basic auth setup

### 4. MixpanelClientFactory (Updated)

```php
public static function create(): MixpanelClientInterface
{
    $config = new MixpanelConfig(
        dataApiBaseUrl: self::getRequiredString('mixpanel.base_url'),
        serviceAccountUsername: self::getRequiredString('mixpanel.service_account_username'),
        serviceAccountPassword: self::getRequiredString('mixpanel.service_account_password'),
        projectId: self::getRequiredString('mixpanel.project_id'),
        lookupTableId: self::getRequiredString('mixpanel.utm_campaign_lookup_table_id'),
        timeout: (int) \config('mixpanel.timeout', 30),
        retryTimes: (int) \config('mixpanel.retry_times', 3),
        retryDelay: (int) \config('mixpanel.retry_delay', 100),
    );

    $transport = new MixpanelHttpTransport($config);

    return new MixpanelClient($transport, $config);
}
```

---

## Testing Strategy

### MixpanelConfigTest (~20 test cases)

Follow ReviewsIoConfigTest pattern exactly:
- Valid config construction
- Empty string validation (5 fields)
- Timeout boundary tests (1, 300, 0, 301)
- Retry times boundary tests (0, 10, -1, 11)
- Retry delay boundary tests (0, 5000, -1, 5001)
- Constants accessibility

### MixpanelHttpTransportTest (~15 test cases)

**Location**: `tests/Feature/Infrastructure/AdSpend/Mixpanel/MixpanelHttpTransportTest.php`

- Basic auth header construction
- Rate limit (429) handling with Retry-After extraction
- Connection exception translation
- Request exception translation (4xx, 5xx)
- `verifyAuth()` calls correct endpoint (MAIN_API_URL + /api/app/me)
- `postJson` sets correct Content-Type (application/json)
- `putCsv` sets text/csv Content-Type

### MixpanelClientTest (Existing - Update setUp)

```php
protected function setUp(): void
{
    parent::setUp();

    $config = new MixpanelConfig(
        dataApiBaseUrl: self::BASE_URL,
        serviceAccountUsername: self::USERNAME,
        serviceAccountPassword: self::PASSWORD,
        projectId: self::PROJECT_ID,
        lookupTableId: self::LOOKUP_TABLE_ID,
    );

    $transport = new MixpanelHttpTransport($config);
    $this->client = new MixpanelClient($transport, $config);
}
```

All 35+ existing tests should pass unchanged (HTTP behavior identical).

---

## Implementation Order

1. **MixpanelConfig** - No dependencies
2. **MixpanelConfigTest** - Validate config
3. **MixpanelHttpTransport** - Depends on Config
4. **MixpanelHttpTransportTest** - Validate transport
5. **MixpanelClient refactor** - Depends on Config + Transport
6. **MixpanelClientTest update** - Update setUp()
7. **MixpanelClientFactory update** - Wire everything
8. **config/mixpanel.php update** - Add technical settings
9. **Delete MixpanelApiException** - Dead code cleanup
10. **Run full test suite** - `make check`

---

## CLAUDE.md Documentation

**Location**: `app/Infrastructure/Mixpanel/CLAUDE.md`

**Content outline**:

```markdown
# Mixpanel API Client - Architecture Notes

## Multi-Method Transport Pattern

Unlike ReviewsIo (single GET), Mixpanel requires three distinct HTTP operations:

| Method | Use Case | Retry | Content-Type |
|--------|----------|-------|--------------|
| `verifyAuth()` | Connectivity check | No | - |
| `postJson()` | Event imports | Yes | application/json |
| `putCsv()` | Lookup table | Yes | text/csv |

## When to Use Each Method

- **verifyAuth**: Health checks, fail-fast credential validation
- **postJson**: Sending structured data (events, profiles)
- **putCsv**: Bulk data replacement (lookup tables)

## Dual Base URL Design

- `MAIN_API_URL` (mixpanel.com): Authentication/account endpoints
- `dataApiBaseUrl` (api.mixpanel.com): Data ingestion endpoints

## Adding New Endpoints

Follow the existing pattern - add a new transport method if the
HTTP characteristics differ (content-type, timeout, retry policy).
```

---

## Bug Fix: ConnectionException in verifyConnectivity

**Current Issue**: `verifyConnectivity()` doesn't catch `ConnectionException` (line 68-75 only catches `RequestException`)

**Fix**: Transport's `verifyAuth()` will handle both exception types consistently.

---

## Config File Update

**File**: `config/mixpanel.php`

```php
return [
    // Existing
    'base_url' => env('MIXPANEL_BASE_URL', 'https://api.mixpanel.com'),
    'project_id' => env('MIXPANEL_PROJECT_ID'),
    'service_account_username' => env('MIXPANEL_SERVICE_ACCOUNT_USERNAME'),
    'service_account_password' => env('MIXPANEL_SERVICE_ACCOUNT_PASSWORD'),
    'utm_campaign_lookup_table_id' => env('MIXPANEL_UTM_CAMPAIGN_LOOKUP_TABLE_ID'),

    // NEW: Technical settings (single timeout for all operations)
    'timeout' => 30,
    'retry_times' => 3,
    'retry_delay' => 100,
];
```

---

## Critical Files Reference

**Template Pattern (to follow)**:
- `app/Infrastructure/ReviewsIo/ReviewsIoConfig.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoClient.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoClientFactory.php`

**Files to Modify**:
- `app/Infrastructure/Mixpanel/MixpanelClient.php`
- `app/Infrastructure/Mixpanel/MixpanelClientFactory.php`
- `config/mixpanel.php`
- `tests/Feature/Infrastructure/AdSpend/Mixpanel/MixpanelClientTest.php`

**Files to Create**:
- `app/Infrastructure/Mixpanel/MixpanelConfig.php`
- `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php`
- `app/Infrastructure/Mixpanel/CLAUDE.md`
- `tests/Unit/Infrastructure/Mixpanel/MixpanelConfigTest.php`
- `tests/Feature/Infrastructure/AdSpend/Mixpanel/MixpanelHttpTransportTest.php`

**Files to Delete**:
- `app/Infrastructure/Mixpanel/Exceptions/MixpanelApiException.php`
- `app/Infrastructure/Mixpanel/Exceptions/` (empty directory)