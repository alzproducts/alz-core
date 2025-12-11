# Bing Ads Integration Plan

Replicate `SyncGoogleAdsToMixpanelJob` for Bing Ads following the established Clean Architecture pattern.

**Scope**: Metrics sync only (no lookup table job needed for Bing).

---

## ⚠️ Critical: PHP SDK Limitations

The official `microsoft/bingads` PHP SDK **lacks helper classes** available in .NET/Java/Python:
- ❌ No `ReportingServiceManager`
- ❌ No `BulkServiceManager`

**Impact**: Must implement async reporting manually via raw SOAP operations:

```
1. Create ReportingServiceClient (SOAP)
2. Call SubmitGenerateReport → Get ReportRequestId
3. Poll with PollGenerateReport until Status = Success
4. HTTP GET the download URL → ZIP file
5. Extract CSV from ZIP
6. Parse CSV rows
```

This significantly increases `BingAdsTransport` complexity compared to `GoogleAdsTransport`.

## Prerequisites (Manual Steps)

### 1. Microsoft Advertising Developer Registration

1. **Create Microsoft Advertising Account** at [ads.microsoft.com](https://ads.microsoft.com)
   - Use Work/School Account (not personal) for full API access

2. **Get Developer Token** from [Developer Portal](https://developers.bingads.microsoft.com/Account)
   - Sign in with Super Admin credentials → Request Token
   - For sandbox testing, use token: `BBD37VB98`

3. **Register Azure AD Application** at [Azure Portal](https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade)
   - Name: "AlzCore Bing Ads Integration"
   - Account types: "Accounts in any organizational directory and personal Microsoft accounts"
   - Redirect URI: `http://localhost:8000/bing-ads/callback` (dev) or production URL
   - Note the **Application (client) ID** → `BING_ADS_CLIENT_ID`

4. **Create Client Secret** (Certificates & secrets → New client secret)
   - Copy immediately → `BING_ADS_CLIENT_SECRET`

5. **Configure API Permissions**
   - Add permission → APIs my organization uses → "Microsoft Advertising"
   - Delegated: `msads.manage`
   - Grant admin consent

6. **Generate Refresh Token** via OAuth flow:
   ```
   https://login.microsoftonline.com/common/oauth2/v2.0/authorize?
     client_id={CLIENT_ID}&response_type=code&
     redirect_uri={REDIRECT_URI}&
     scope=offline_access%20https://ads.microsoft.com/msads.manage
   ```
   Exchange code for tokens → `BING_ADS_REFRESH_TOKEN`

7. **Find Account IDs** in Microsoft Advertising UI:
   - Customer ID (10 chars) → `BING_ADS_CUSTOMER_ID`
   - Account ID (8 chars) → `BING_ADS_ACCOUNT_ID`

### 2. Composer Installation

```bash
composer require microsoft/bingads
```

**Required PHP extensions**: `ext-soap`, `ext-curl`, `ext-openssl`, `ext-zip`

---

## Implementation Steps

### Phase 1: Configuration & Foundation

#### 1.1 Create `config/bing-ads.php`
```php
return [
    'client_id' => env('BING_ADS_CLIENT_ID'),
    'client_secret' => env('BING_ADS_CLIENT_SECRET'),
    'refresh_token' => env('BING_ADS_REFRESH_TOKEN'),
    'developer_token' => env('BING_ADS_DEVELOPER_TOKEN'),
    'account_id' => env('BING_ADS_ACCOUNT_ID'),
    'customer_id' => env('BING_ADS_CUSTOMER_ID'),
    'environment' => env('BING_ADS_ENVIRONMENT', 'Production'),
    'report_poll_interval_seconds' => env('BING_ADS_REPORT_POLL_INTERVAL', 10),
    'report_poll_max_attempts' => env('BING_ADS_REPORT_POLL_MAX_ATTEMPTS', 30),
];
```

#### 1.2 Update `.env.example`
```bash
# Bing Ads (Microsoft Advertising)
BING_ADS_CLIENT_ID=
BING_ADS_CLIENT_SECRET=
BING_ADS_REFRESH_TOKEN=
BING_ADS_DEVELOPER_TOKEN=
BING_ADS_ACCOUNT_ID=
BING_ADS_CUSTOMER_ID=
BING_ADS_ENVIRONMENT=Production
```

#### 1.3 Update `deptrac.yaml`
Add new layer:
```yaml
- name: BingAdsSdk
  collectors:
    - type: classLike
      value: ^Microsoft\\BingAds\\.*
```
Add to Infrastructure ruleset: `- BingAdsSdk`
Add empty ruleset: `BingAdsSdk: ~`

---

### Phase 2: Infrastructure Layer

#### File Structure
```
app/Infrastructure/BingAds/
├── BingAdsConfig.php
├── BingAdsSession.php                # VO: accessToken, expiresAt, isExpired()
├── BingAdsSessionManager.php         # Auth lifecycle: cache, locks, token refresh (like Linnworks)
├── BingAdsTransport.php              # readonly: SOAP + polling + ZIP extraction (delegates auth to SessionManager)
├── BingAdsClient.php
├── BingAdsClientFactory.php          # Includes currency validation
├── Exceptions/
│   └── InvalidBingAdsResponseException.php
├── Transformers/
│   └── BingAdsReportTransformer.php  # CSV parsing only (no campaign transformer - metrics only)
└── CLAUDE.md
```

#### 2.1 `BingAdsConfig.php`
- Immutable VO with constructor validation
- Properties: `clientId`, `clientSecret`, `refreshToken`, `developerToken`, `accountId`, `customerId`, `environment`, `reportPollInterval`, `reportPollMaxAttempts`
- Throw `RuntimeException` for empty required fields
- Pattern: Copy from `GoogleAdsConfig.php`

#### 2.2 `BingAdsSession.php` & `BingAdsSessionManager.php`

**Pattern**: Follow `LinnworksSessionManager` (app/Infrastructure/Linnworks/LinnworksSessionManager.php)

**BingAdsSession** (immutable VO):
```php
final readonly class BingAdsSession
{
    public function __construct(
        public string $accessToken,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    public static function fromOAuthResponse(OAuthTokens $tokens, int $ttlBuffer = 60): self
    {
        return new self(
            accessToken: $tokens->AccessToken,
            expiresAt: new DateTimeImmutable("+{$tokens->ExpiresIn - $ttlBuffer} seconds"),
        );
    }
}
```

**BingAdsSessionManager** (manages auth lifecycle):
```php
final class BingAdsSessionManager  // NOT readonly - uses external cache
{
    private const string CACHE_KEY = 'bing_ads:session';
    private const string LOCK_KEY = 'bing_ads:session:lock';
    private const int LOCK_TIMEOUT = 30;
    private const int LOCK_WAIT = 10;

    public function __construct(
        private readonly BingAdsConfig $config,
        private readonly CacheManager $cache,
    ) {}

    public function getSession(): BingAdsSession
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if ($cached instanceof BingAdsSession && !$cached->isExpired()) {
            return $cached;
        }
        return $this->authenticateWithLock();
    }

    private function authenticateWithLock(): BingAdsSession
    {
        $lock = $this->cache->lock(self::LOCK_KEY, self::LOCK_TIMEOUT);

        try {
            $lock->block(self::LOCK_WAIT);

            // ⚠️ CRITICAL: Double-check after acquiring lock (TOCTOU prevention)
            $cached = $this->cache->get(self::CACHE_KEY);
            if ($cached instanceof BingAdsSession && !$cached->isExpired()) {
                return $cached;
            }

            return $this->authenticate();
        } finally {
            $lock->release();
        }
    }

    private function authenticate(): BingAdsSession { /* OAuth refresh token call */ }

    private function cacheSession(BingAdsSession $session): void
    {
        // ⚠️ CRITICAL: TTL must be calculated from expiry, not fixed
        $ttl = $session->expiresAt->getTimestamp() - time();
        $this->cache->put(self::CACHE_KEY, $session, max(1, $ttl));
    }
}
```

**Benefits over embedded token state**:
- Redis cache survives process restarts (Octane-safe)
- Atomic locks prevent thundering herd
- BingAdsTransport stays `readonly`

#### 2.3 `BingAdsTransport.php`

**Now `final readonly`** - delegates auth to SessionManager:

```php
final readonly class BingAdsTransport
{
    public function __construct(
        private BingAdsSessionManager $sessionManager,
        private BingAdsConfig $config,
        private ClientInterface $httpClient,  // ⚠️ REQUIRED: For ZIP download (Guzzle)
    ) {}

    public function getCampaignPerformanceReport(DateRange $range): string
    {
        $session = $this->sessionManager->getSession();  // Gets valid token
        // ... SOAP calls using $session->accessToken
    }
}
```

Key differences from Google Ads:
- **SOAP-based** (not gRPC) - catch `SoapFault` instead of `ApiException`
- **Async reporting** - Submit → Poll → Download ZIP → Extract CSV
- **No Retry-After header** - use fixed 60s for rate limits
- **HTTP client injected** - for testable ZIP downloads (not `Http::` facade)

**Public Methods**:
```php
public function getCampaignPerformanceReport(DateRange $range): string  // Returns CSV content
public function getAccountInfo(): AccountInfo  // For currency validation
```

**Private Methods**:
```php
private function submitReport(CampaignPerformanceReportRequest $request): string  // Returns request ID
private function pollUntilComplete(string $requestId): string  // Returns download URL
private function downloadAndExtractCsv(string $url): string  // Uses ZipArchive + injected HTTP client
private function handleSoapFault(SoapFault $e): DomainException
private function extractErrorCode(SoapFault $e): ?int
```

**⚠️ CRITICAL: Poll Status Handling**

Bing reports can return `Error` status - must handle explicitly:

```php
private function pollUntilComplete(string $requestId): string
{
    for ($i = 0; $i < $this->config->reportPollMaxAttempts; $i++) {
        $status = $this->checkReportStatus($requestId);

        match ($status->Status) {
            'Success' => return $status->ReportDownloadUrl,
            'Error' => throw new ExternalServiceUnavailableException(
                'Bing Ads',
                message: "Report generation failed: {$status->StatusMessage}",
            ),
            'Pending' => sleep($this->config->reportPollInterval),
        };
    }

    throw new ExternalServiceUnavailableException(
        'Bing Ads',
        message: 'Report poll timeout after ' . $this->config->reportPollMaxAttempts . ' attempts',
    );
}
```

**Exception Mapping**:
| Bing Error Code | Domain Exception |
|-----------------|------------------|
| 117 (CallRateExceeded) | `ExternalServiceUnavailableException(retryAfter: 60)` |
| 105/106 (Auth) | `AuthenticationExpiredException` |
| Poll timeout | `ExternalServiceUnavailableException(retryAfter: null)` |
| Other | `ExternalServiceUnavailableException` |

#### 2.4 `BingAdsClient.php`
- Implements `BingAdsClientInterface`
- Methods: `getSource()`, `getCampaignMetricsByDateRange()`, `getCampaigns()`, `verifyConnectivity()`
- Constructs `CampaignPerformanceReportRequest` with columns: CampaignId, CampaignName, TimePeriod, Spend, Clicks, Impressions, Conversions
- Transforms CSV via `BingAdsReportTransformer`

**⚠️ CRITICAL: Report Aggregation Setting**

Must set `ReportAggregation::Daily` to match Google Ads daily granularity:

```php
private function buildReportRequest(DateRange $range): CampaignPerformanceReportRequest
{
    $request = new CampaignPerformanceReportRequest();
    $request->Aggregation = ReportAggregation::Daily;  // ⚠️ REQUIRED
    $request->Time = new ReportTime();
    $request->Time->CustomDateRangeStart = $this->toDate($range->start);
    $request->Time->CustomDateRangeEnd = $this->toDate($range->end);
    $request->Columns = [
        CampaignPerformanceReportColumn::CampaignId,
        CampaignPerformanceReportColumn::CampaignName,
        CampaignPerformanceReportColumn::TimePeriod,
        CampaignPerformanceReportColumn::Spend,
        CampaignPerformanceReportColumn::Clicks,
        CampaignPerformanceReportColumn::Impressions,
        CampaignPerformanceReportColumn::Conversions,
    ];
    return $request;
}
```

#### 2.5 `BingAdsClientFactory.php`
- Validates config at boot time
- **Validates account currency is GBP** (fail-fast if not)
- Creates: BingAdsConfig → OAuth2 → AuthorizationData → ServiceClient → BingAdsTransport → BingAdsClient
- Pattern: Copy from `GoogleAdsClientFactory.php`

**Currency Validation** (at boot time):
```php
public static function create(): BingAdsClientInterface
{
    $config = self::buildConfig();
    $transport = self::buildTransport($config);

    // Fail fast if account currency is not GBP
    $accountInfo = $transport->getAccountInfo();
    if ($accountInfo->CurrencyCode !== 'GBP') {
        throw new RuntimeException(
            "Bing Ads account currency must be GBP, got: {$accountInfo->CurrencyCode}"
        );
    }

    return new BingAdsClient($transport);
}
```

**Rationale**: Domain `CampaignMetrics::$costInPounds` expects GBP. Converting currencies at runtime adds complexity and external dependencies. Fail-fast matches existing patterns.

#### 2.6 Transformers

**BingAdsReportTransformer** (only transformer needed - no lookup table):
- Parses CSV (handle header row, skip Bing metadata rows at top)
- Maps columns to `CampaignMetrics` VO
- Date format: Handle both MM/DD/YYYY and YYYY-MM-DD (Bing format varies by account settings)
- Currency: Already validated as GBP at boot time

```php
final class BingAdsReportTransformer  // NOT readonly - all static methods (readonly meaningless)
{
    /**
     * @return array<int, CampaignMetrics>
     * @throws InvalidBingAdsResponseException
     */
    public static function fromCsv(string $csvContent): array
    {
        $lines = self::parseLines($csvContent);
        $headers = self::parseHeaders($lines);

        return array_map(
            fn(array $row) => self::toMetrics($row, $headers),
            self::dataRows($lines)
        );
    }

    private static function toMetrics(array $row, array $headers): CampaignMetrics
    {
        return new CampaignMetrics(
            campaignId: (int) $row[$headers['CampaignId']],
            campaignName: $row[$headers['CampaignName']],
            date: self::normalizeDate($row[$headers['TimePeriod']]),
            costInPounds: (float) $row[$headers['Spend']],
            clicks: (int) $row[$headers['Clicks']],
            impressions: (int) $row[$headers['Impressions']],
            conversions: (float) $row[$headers['Conversions']],
        );
    }

    private static function normalizeDate(string $date): string
    {
        // Handle MM/DD/YYYY → YYYY-MM-DD
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m)) {
            return "{$m[3]}-{$m[1]}-{$m[2]}";
        }
        return $date; // Already YYYY-MM-DD
    }
}
```

#### 2.7 `InvalidBingAdsResponseException.php`
- Static factories: `missingField()`, `invalidValue()`, `invalidCsvFormat()`, `invalidZipArchive()`
- Pattern: Copy from `InvalidGoogleAdsResponseException.php`

**ZIP Archive Error Handling**:
```php
public static function invalidZipArchive(string $reason): self
{
    return new self("Invalid ZIP archive from Bing Ads: {$reason}");
}

// Usage in BingAdsTransport::downloadAndExtractCsv()
private function downloadAndExtractCsv(string $url): string
{
    $response = $this->httpClient->get($url);
    $tempFile = tempnam(sys_get_temp_dir(), 'bing_report_');
    file_put_contents($tempFile, $response->getBody());

    $zip = new ZipArchive();
    if ($zip->open($tempFile) !== true) {
        throw InvalidBingAdsResponseException::invalidZipArchive('Failed to open archive');
    }

    if ($zip->numFiles === 0) {
        throw InvalidBingAdsResponseException::invalidZipArchive('Empty archive');
    }

    $csv = $zip->getFromIndex(0);
    $zip->close();
    unlink($tempFile);

    if ($csv === false) {
        throw InvalidBingAdsResponseException::invalidZipArchive('Failed to extract CSV');
    }

    return $csv;
}
```

---

### Phase 3: Application Layer

#### 3.1 `app/Application/Contracts/BingAdsClientInterface.php`
```php
interface BingAdsClientInterface extends AdSpendClientInterface
{
    public function verifyConnectivity(): void;
}
```

---

### Phase 4: Service Provider

#### 4.1 `app/Providers/BingAdsServiceProvider.php`
- `implements DeferrableProvider`
- Singleton binding for `BingAdsClientInterface`
- Contextual binding: `SyncBingAdsToMixpanelJob` → `SyncAdSpendUseCase` with Bing client
- Pattern: Copy from `GoogleAdsServiceProvider.php`

**Full Service Provider Wiring**:
```php
final class BingAdsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        // 1. Config VO
        $this->app->singleton(BingAdsConfig::class, fn() => BingAdsConfig::fromConfig());

        // 2. Session Manager (needs CacheManager)
        $this->app->singleton(BingAdsSessionManager::class, fn($app) => new BingAdsSessionManager(
            $app->make(BingAdsConfig::class),
            $app->make(CacheManager::class),  // ⚠️ CRITICAL: Must inject
        ));

        // 3. Transport (needs SessionManager + HTTP client)
        $this->app->singleton(BingAdsTransport::class, fn($app) => new BingAdsTransport(
            $app->make(BingAdsSessionManager::class),
            $app->make(BingAdsConfig::class),
            new \GuzzleHttp\Client(),  // For ZIP downloads
        ));

        // 4. Client interface
        $this->app->singleton(BingAdsClientInterface::class, fn($app) => new BingAdsClient(
            $app->make(BingAdsTransport::class),
        ));

        // 5. Contextual binding for job
        $this->app->when(SyncBingAdsToMixpanelJob::class)
            ->needs(SyncAdSpendUseCase::class)
            ->give(fn($app) => new SyncAdSpendUseCase(
                $app->make(BingAdsClientInterface::class),
                $app->make(MixpanelClientInterface::class),
            ));
    }

    public function provides(): array
    {
        return [
            BingAdsConfig::class,
            BingAdsSessionManager::class,
            BingAdsTransport::class,
            BingAdsClientInterface::class,
        ];
    }
}
```

#### 4.2 Register in `bootstrap/providers.php`
```php
App\Providers\BingAdsServiceProvider::class,
```

---

### Phase 5: Presentation Layer

#### 5.1 `app/Presentation/Jobs/SyncBingAdsToMixpanelJob.php`
- Mirror `SyncGoogleAdsToMixpanelJob.php` structure
- Same retry logic: `$tries = 5`, `$backoff = [60, 120, 240, 480, 960]`
- Same exception handling: PayloadSerializationException/AuthenticationExpiredException → `fail()`, ExternalServiceUnavailableException → `release()`

**⚠️ Critical: Job Timeout**

Report polling can take 5+ minutes (10s × 30 attempts = 300s). Must set timeout higher than max polling time:

```php
final class SyncBingAdsToMixpanelJob implements ShouldQueue
{
    public int $tries = 5;
    public array $backoff = [60, 120, 240, 480, 960];
    public int $timeout = 600;  // 10 minutes - REQUIRED for async reporting

    // ... rest mirrors Google job
}
```

**Rationale**: Default Laravel job timeout is 60s. Without explicit timeout, jobs will timeout during polling and retry unnecessarily, wasting API quota.

---

### Phase 6: Testing

#### Unit Tests
| Test File | Coverage |
|-----------|----------|
| `BingAdsConfigTest.php` | Empty field validation, environment validation |
| `BingAdsSessionTest.php` | isExpired() logic, fromOAuthResponse() factory |
| `BingAdsSessionManagerTest.php` | Cache hit/miss, lock acquisition, thundering herd prevention |
| `BingAdsTransportTest.php` | SoapFault error codes → domain exceptions, poll timeout |
| `BingAdsClientTest.php` | Report request building, date range formatting |
| `BingAdsReportTransformerTest.php` | CSV parsing, MM/DD/YYYY and YYYY-MM-DD formats, missing columns, empty data |
| `BingAdsClientFactoryTest.php` | Currency validation (GBP required), config validation |
| `SyncBingAdsToMixpanelJobTest.php` | Retry/fail logic, timeout config |

#### Integration Test
- Verify connectivity with sandbox credentials

---

## Critical Files (Reference)

| Purpose | Reference File |
|---------|----------------|
| Config VO | `app/Infrastructure/GoogleAds/GoogleAdsConfig.php` |
| **Session Manager** | `app/Infrastructure/Linnworks/LinnworksSessionManager.php` ← **Primary pattern for auth lifecycle** |
| **Session VO** | `app/Infrastructure/Linnworks/LinnworksSession.php` |
| Transport | `app/Infrastructure/GoogleAds/GoogleAdsTransport.php` |
| Client | `app/Infrastructure/GoogleAds/GoogleAdsClient.php` |
| Factory | `app/Infrastructure/GoogleAds/GoogleAdsClientFactory.php` |
| Interface | `app/Application/Contracts/GoogleAdsClientInterface.php` |
| Provider | `app/Providers/GoogleAdsServiceProvider.php` |
| Job | `app/Presentation/Jobs/SyncGoogleAdsToMixpanelJob.php` |
| Deptrac | `deptrac.yaml` |

---

## Key Architectural Differences: Bing vs Google

| Aspect | Google Ads | Bing Ads |
|--------|------------|----------|
| Protocol | gRPC | SOAP |
| Query | GAQL (instant) | Report Request (async) |
| Response | PagedListResponse | ZIP → CSV |
| Rate limit | Retry-After header | Fixed 60s |
| Token refresh | SDK handles internally | `BingAdsSessionManager` (like Linnworks) |
| Transport class | `readonly` | `readonly` (delegates auth to SessionManager) |

---

## Estimated Effort

| Phase | Tasks | Estimate |
|-------|-------|----------|
| 1 | Config, env, deptrac | 1 hour |
| 2 | Infrastructure (transport is complex: SOAP + polling + ZIP + token refresh) | 6-8 hours |
| 3 | Interface | 15 min |
| 4 | Service provider | 30 min |
| 5 | Job (with timeout config) | 30 min |
| 6 | Tests (transport needs extensive mocking) | 3-4 hours |
| **Total** | | **12-15 hours** |

**Note**: Estimate increased from 8-11 hours due to PHP SDK limitations requiring manual SOAP implementation.

---

## Open Questions (Resolved)

- [x] SDK choice → Official `microsoft/bingads`
- [x] Auth method → OAuth 2.0 refresh token
- [x] Registration guide needed → Yes, included above
- [x] Scope → Metrics sync only (no lookup table job for Bing)
- [x] Currency handling → Validate GBP at boot, fail-fast if not

---

## Plan Review (2025-12-09)

**Issues identified and corrected:**

| Priority | Issue | Resolution |
|----------|-------|------------|
| HIGH | HTTP client for ZIP download not specified | Added `ClientInterface $httpClient` to Transport constructor |
| HIGH | Report `Error` status not handled | Added explicit `match` handling with exception throw |
| HIGH | TOCTOU race condition in SessionManager | Added double-check after lock (verified from Linnworks pattern) |
| MEDIUM | Cache TTL calculation missing | Added `cacheSession()` method with dynamic TTL calculation |
| MEDIUM | ZIP extraction error handling | Added `invalidZipArchive()` factory + full extraction code |
| MEDIUM | ReportAggregation enum missing | Added `ReportAggregation::Daily` in request building |
| MEDIUM | Service provider incomplete | Added full wiring with CacheManager dependency |
| LOW | `readonly` on static-only class | Removed from `BingAdsReportTransformer` |

**Verified correct:**
- ✅ `AuthenticationExpiredException` exists in Domain layer
- ✅ `AdSpendClientInterface` exists with correct signature
- ✅ `AdSource::Bing` already defined in enum
- ✅ Linnworks TOCTOU pattern verified (lines 86-92)
