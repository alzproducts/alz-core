# COR-136: Google Ads Offline Conversion Tracking — Infrastructure Layer

## Context

The frontend captures two business events — **Lead Received** (form submission) and **Quote Issued** — that should be attributed back to Google Ads clicks. The Infrastructure layer needs to upload these as offline conversions via Google Ads `ConversionUploadService` API. Each upload sends both a gclid (direct click link) and a hashed email (Enhanced Conversions fallback) to maximise match rate.

This plan is Infrastructure-only. Application use cases and job dispatch are Phase 2.

---

## Architecture Overview

Follows the existing `Config → Transport → Client → Factory` pattern.

The **conversion client** is a separate concern from the existing ad-spend read client (`GoogleAdsClient`). They share the same `SdkGoogleAdsClient` and `GoogleAdsConfig` but use different SDK services:
- Existing: `GoogleAdsServiceClient::search()` (GAQL queries)  
- New: `ConversionUploadServiceClient::uploadClickConversions()`

---

## Files to Create / Modify

### New files
1. `app/Domain/Conversion/Enums/ConversionType.php`
2. `app/Application/Contracts/GoogleAdsConversionClientInterface.php`
3. `app/Infrastructure/GoogleAds/GoogleAdsConversionClient.php`
4. `tests/Unit/Infrastructure/GoogleAds/GoogleAdsConversionClientTest.php`
5. `tests/Unit/Infrastructure/GoogleAds/GoogleAdsConversionTransportTest.php`

### Modified files
6. `app/Infrastructure/GoogleAds/GoogleAdsConfig.php`
7. `app/Infrastructure/GoogleAds/GoogleAdsTransport.php` (add `uploadClickConversion()` method)
8. `app/Infrastructure/GoogleAds/GoogleAdsClientFactory.php`
9. `config/google-ads.php`
10. `app/Providers/GoogleAdsServiceProvider.php`

---

## Step-by-Step Implementation

### 1. Domain enum — `ConversionType`

**File:** `app/Domain/Conversion/Enums/ConversionType.php`

```php
namespace App\Domain\Conversion\Enums;

enum ConversionType: string
{
    case LeadReceived = 'lead_received';
    case QuoteIssued  = 'quote_issued';
}
```

Rationale: This is a business concept (types of conversions we track), not Google-specific. New `Conversion` bounded context in Domain.

---

### 2. Application contract — `GoogleAdsConversionClientInterface`

**File:** `app/Application/Contracts/GoogleAdsConversionClientInterface.php`

```php
namespace App\Application\Contracts;

use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;

interface GoogleAdsConversionClientInterface
{
    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException When Google rejects the conversion data (e.g., expired gclid, missing action ID)
     */
    public function uploadConversion(
        ConversionType $type,
        string $gclid,
        string $email,        // plain email — infra hashes internally
        DateTimeImmutable $convertedAt,
        ?Money $value,        // null = use conversion action's default value in Google Ads
    ): void;
}
```

Kept separate from `GoogleAdsClientInterface` (ISP — reads vs writes, different SDK service).

---

### 3. Extend `GoogleAdsConfig`

**File:** `app/Infrastructure/GoogleAds/GoogleAdsConfig.php`

Add two nullable fields to the readonly constructor (nullable so existing `create()` method in factory doesn't break):

```php
public ?string $leadConversionActionId = null,
public ?string $quoteConversionActionId = null,
```

Add empty-string validation in the constructor (mirroring the existing `loginCustomerId` pattern):

```php
if ($leadConversionActionId === '') {
    throw new InvalidConfigurationException(
        'google-ads.lead_conversion_action_id',
        'Google Ads lead conversion action ID cannot be empty when provided',
    );
}
if ($quoteConversionActionId === '') {
    throw new InvalidConfigurationException(
        'google-ads.quote_conversion_action_id',
        'Google Ads quote conversion action ID cannot be empty when provided',
    );
}
```

---

### 4. Extend existing transport — `GoogleAdsTransport`

**File:** `app/Infrastructure/GoogleAds/GoogleAdsTransport.php`

Add a new `uploadClickConversion()` method alongside the existing `search()`. Both use the same `SdkGoogleAdsClient` and reuse the existing exception translation helpers (`handleApiException`, `handleRateLimit`, etc.) — no duplication.

```php
/**
 * @throws ExternalServiceUnavailableException
 * @throws AuthenticationExpiredException
 * @throws InvalidApiRequestException When Google rejects the conversion data (partial failure)
 */
public function uploadClickConversion(UploadClickConversionsRequest $request): void
{
    try {
        $response = $this->sdkClient
            ->getConversionUploadServiceClient()
            ->uploadClickConversions($request);

        $this->handlePartialFailure($response);
    } catch (ApiException $e) {
        throw $this->handleApiException($e);
    }
}
```

**New private method — `handlePartialFailure()`:** checks `$response->getPartialFailureError()` — if non-null and `getCode() !== 0`, logs error message + code and throws `InvalidApiRequestException` (per-conversion data was rejected — request-level fault, not service availability).

```php
private function handlePartialFailure(UploadClickConversionsResponse $response): void
{
    $error = $response->getPartialFailureError();

    if ($error === null || $error->getCode() === 0) {
        return;
    }

    Log::error(self::SERVICE_NAME . ' conversion upload partial failure', [
        'code' => $error->getCode(),
        'message' => $error->getMessage(),
    ]);

    throw new InvalidApiRequestException(self::SERVICE_NAME, $error->getMessage());
}
```

**Exception translation matrix** (existing, reused as-is):
| gRPC Code | Domain Exception |
|-----------|-----------------|
| `RESOURCE_EXHAUSTED` | `ExternalServiceUnavailableException` (with retry-after) |
| `PERMISSION_DENIED` / `UNAUTHENTICATED` | `AuthenticationExpiredException` |
| All others | `ExternalServiceUnavailableException` |

New imports needed: `UploadClickConversionsRequest`, `UploadClickConversionsResponse`, `InvalidApiRequestException`.

---

### 5. New client — `GoogleAdsConversionClient`

**File:** `app/Infrastructure/GoogleAds/GoogleAdsConversionClient.php`

```php
final readonly class GoogleAdsConversionClient implements GoogleAdsConversionClientInterface
{
    public function __construct(
        private GoogleAdsTransport $transport,
        private GoogleAdsConfig $config,
    ) {}

    public function uploadConversion(
        ConversionType $type,
        string $gclid,
        string $email,
        DateTimeImmutable $convertedAt,
        ?Money $value,
    ): void {
        Assert::notEmpty($gclid, 'gclid cannot be empty');
        Assert::notEmpty($email, 'email cannot be empty');

        $conversion = $this->buildClickConversion($type, $gclid, $email, $convertedAt, $value);

        $request = new UploadClickConversionsRequest();
        $request->setCustomerId($this->config->customerId);
        $request->setConversions([$conversion]);
        $request->setPartialFailure(true);

        $this->transport->uploadClickConversion($request);
    }
}
```

**`buildClickConversion()` logic:**
1. Build action resource name: `sprintf('customers/%s/conversionActions/%s', $this->config->customerId, $this->resolveActionId($type))`
2. Format datetime: `$convertedAt->format('Y-m-d H:i:sP')`
3. Normalise + hash email: `hash('sha256', strtolower(trim($email)))`
4. Build `UserIdentifier`: `(new UserIdentifier())->setHashedEmail($hashedEmail)`
5. Build `ClickConversion` with all fields; optionally set `setConversionValue()` + `setCurrencyCode()` if `$value !== null` (using `$value->toNet()` and `$value->currency`)

**`resolveActionId()` private method:**
```php
private function resolveActionId(ConversionType $type): string
{
    $actionId = match ($type) {
        ConversionType::LeadReceived => $this->config->leadConversionActionId,
        ConversionType::QuoteIssued  => $this->config->quoteConversionActionId,
    };

    // Factory guarantees non-null when creating conversion client.
    // Assert here is a safety net + PHPStan narrowing — not a runtime defence.
    Assert::notNull($actionId);

    return $actionId;
}
```

---

### 6. Extend `GoogleAdsClientFactory`

**File:** `app/Infrastructure/GoogleAds/GoogleAdsClientFactory.php`

Add two new methods:

**Strategy:** Refactor `createConfig()` to extract a `readBaseConfigFields()` private helper that returns an associative array of validated base fields. Both factory methods then construct the final `GoogleAdsConfig` from that array.

```php
/**
 * Read + validate the 5 base config fields shared by all Google Ads clients.
 *
 * @return array{clientId: string, clientSecret: string, refreshToken: string, developerToken: string, customerId: string, loginCustomerId: ?string}
 */
private static function readBaseConfigFields(): array
{
    // existing validation logic moved here, returns the validated map
    ...
}

private static function createConfig(): GoogleAdsConfig
{
    $base = self::readBaseConfigFields();
    return new GoogleAdsConfig(...$base);
}

public static function createConversionClient(): GoogleAdsConversionClientInterface
{
    $config = self::createConversionConfig();
    $sdkClient = self::buildSdkClient($config);
    $transport = new GoogleAdsTransport($sdkClient, $config);

    return new GoogleAdsConversionClient($transport, $config);
}

private static function createConversionConfig(): GoogleAdsConfig
{
    $base = self::readBaseConfigFields();

    $leadId  = \config('google-ads.lead_conversion_action_id');
    $quoteId = \config('google-ads.quote_conversion_action_id');

    if (!\is_string($leadId)) {
        throw new InvalidConfigurationException('GOOGLE_ADS_LEAD_CONVERSION_ID');
    }
    if (!\is_string($quoteId)) {
        throw new InvalidConfigurationException('GOOGLE_ADS_QUOTE_CONVERSION_ID');
    }

    return new GoogleAdsConfig(
        ...$base,
        leadConversionActionId: $leadId,
        quoteConversionActionId: $quoteId,
    );
}
```

Empty-string validation happens in `GoogleAdsConfig`'s constructor (see Step 3).

---

### 7. Config — `config/google-ads.php`

Add two new keys:

```php
'lead_conversion_action_id'  => env('GOOGLE_ADS_LEAD_CONVERSION_ID'),
'quote_conversion_action_id' => env('GOOGLE_ADS_QUOTE_CONVERSION_ID'),
```

---

### 8. Service provider — `GoogleAdsServiceProvider`

**File:** `app/Providers/GoogleAdsServiceProvider.php`

Add to `register()`:

```php
private function registerConversionClient(): void
{
    $this->app->singleton(
        GoogleAdsConversionClientInterface::class,
        static fn(): GoogleAdsConversionClientInterface => GoogleAdsClientFactory::createConversionClient(),
    );
}
```

Add `registerConversionClient()` call in `register()`, and add `GoogleAdsConversionClientInterface::class` to `provides()` array.

---

### 9. Tests

**File:** `tests/Unit/Infrastructure/GoogleAds/GoogleAdsConversionClientTest.php`

**Client tests** (`GoogleAdsConversionClientTest`): Mock `GoogleAdsTransport`, use real protobuf objects (never mock protobuf — causes segfaults per existing test notes).

Key test cases:
- `it_builds_click_conversion_with_correct_gclid_and_action_resource_name()`
- `it_hashes_and_normalises_email_before_sending()`
- `it_sets_conversion_value_and_currency_when_money_provided()`
- `it_does_not_set_conversion_value_when_money_is_null()`
- `it_formats_datetime_in_google_ads_format()` — assert `Y-m-d H:i:sP`
- `it_sets_partial_failure_to_true()`
- `it_maps_lead_received_type_to_lead_conversion_action_id()`
- `it_maps_quote_issued_type_to_quote_conversion_action_id()`
- `it_throws_when_gclid_is_empty()`
- `it_throws_when_email_is_empty()`
- `it_propagates_transport_exceptions()` — verifies `AuthenticationExpiredException` / `ExternalServiceUnavailableException` pass through

Capture the `UploadClickConversionsRequest` passed to the transport mock with Mockery's `withArgs()` to assert its contents.

**Transport tests** (`GoogleAdsConversionTransportTest`): Tests for the new `uploadClickConversion()` method on `GoogleAdsTransport`. Follows the same pattern as the existing `GoogleAdsTransportTest`:
- `it_translates_resource_exhausted_to_external_service_unavailable_with_retry_after()`
- `it_translates_permission_denied_to_authentication_expired()`
- `it_translates_partial_failure_error_to_invalid_api_request_exception()`
- `it_succeeds_when_partial_failure_error_is_null()`
- `it_succeeds_when_partial_failure_error_code_is_zero()`

---

## SDK Class Reference

| Class | Namespace |
|-------|-----------|
| `ConversionUploadServiceClient` | `Google\Ads\GoogleAds\V22\Services\Client` |
| `UploadClickConversionsRequest` | `Google\Ads\GoogleAds\V22\Services` |
| `UploadClickConversionsResponse` | `Google\Ads\GoogleAds\V22\Services` |
| `ClickConversion` | `Google\Ads\GoogleAds\V22\Services` |
| `UserIdentifier` | `Google\Ads\GoogleAds\V22\Common` |

SDK client getter: `$sdkClient->getConversionUploadServiceClient()` (from `ServiceClientFactoryTrait`)

---

## Verification

```bash
make lint        # Pint + PHPStan + PHPArkitect + Deptrac
make test-quick  # Unit tests only
```

Manual smoke test (after Application/Presentation wiring in Phase 2):
```bash
php artisan tinker --execute="app(\App\Application\Contracts\GoogleAdsConversionClientInterface::class)->uploadConversion(...);"
```
