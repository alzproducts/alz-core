# Fix: Google Ads Authorization Error Handling

## Problem Summary

The `verify:api googleads` command shows a generic "service unavailable" error when the real issue is `DEVELOPER_TOKEN_NOT_APPROVED`. The actual Google Ads API error (Code 7 - PERMISSION_DENIED) is logged but not shown to the user.

**Current behavior**:
```
Failed: External service 'Google Ads' is unavailable
Check: Google Ads OAuth credentials and refresh token
```

**Expected behavior**:
```
Failed: Google Ads: DEVELOPER_TOKEN_NOT_APPROVED - The developer token is only approved for use with test accounts.
Check: Developer token access level in Google Ads API Center
```

## Root Cause

`GoogleAdsTransport::handleApiException()` treats ALL `ApiException` errors as "service unavailable", including permanent authorization errors that require configuration changes.

## Files to Modify

1. `app/Infrastructure/GoogleAds/GoogleAdsTransport.php` - Distinguish auth errors from transient failures
2. `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php` - Show specific error messages
3. `app/Application/Contracts/GoogleAdsClientInterface.php` - Add `@throws AuthenticationExpiredException` PHPDoc
4. `tests/Unit/Infrastructure/GoogleAds/GoogleAdsTransportTest.php` - Add auth error tests, fix existing test

## Implementation Plan

### Step 1: Update GoogleAdsTransport.php

Refactor to follow the established HTTP transport pattern (match expression, union return type, all handlers return):

```php
use App\Domain\Exceptions\AuthenticationExpiredException;
use Google\Rpc\Code;

/**
 * Route API failures to specific handlers by gRPC code.
 *
 * Follows the same pattern as ShopwiredHttpTransport::handleRequestException()
 * but uses gRPC codes instead of HTTP status codes.
 */
private function handleApiException(ApiException $e): AuthenticationExpiredException|ExternalServiceUnavailableException
{
    return match ($e->getCode()) {
        Code::RESOURCE_EXHAUSTED => $this->handleRateLimit($e),
        Code::PERMISSION_DENIED, Code::UNAUTHENTICATED => $this->handleAuthenticationFailure($e),
        default => $this->handleServerError($e),
    };
}

/**
 * Handle RESOURCE_EXHAUSTED (rate limit) - transient, respect Retry-After.
 */
private function handleRateLimit(ApiException $e): ExternalServiceUnavailableException
{
    $retryAfter = $this->extractRetryAfter($e);

    Log::warning(self::SERVICE_NAME . ' API rate limited', [
        'retry_after' => $retryAfter,
        'error' => $e->getMessage(),
    ]);

    return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
}

/**
 * Handle PERMISSION_DENIED/UNAUTHENTICATED - permanent, needs config fix.
 */
private function handleAuthenticationFailure(ApiException $e): AuthenticationExpiredException
{
    $detailedMessage = $this->extractGoogleAdsErrorMessage($e);

    Log::error(self::SERVICE_NAME . ' API authentication failed', [
        'code' => $e->getCode(),
        'error' => $detailedMessage,
    ]);

    return new AuthenticationExpiredException(self::SERVICE_NAME, $detailedMessage, $e);
}

/**
 * Handle other API errors (INTERNAL, UNAVAILABLE, etc.) - transient.
 */
private function handleServerError(ApiException $e): ExternalServiceUnavailableException
{
    Log::error(self::SERVICE_NAME . ' API error', [
        'code' => $e->getCode(),
        'error' => $e->getMessage(),
    ]);

    return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
}

/**
 * Extract specific error message from Google Ads API response.
 *
 * Google Ads errors are nested: message → details → errors[] → errorCode + message
 */
private function extractGoogleAdsErrorMessage(ApiException $e): string
{
    $decoded = json_decode($e->getMessage(), true);

    if (!is_array($decoded)) {
        return $e->getMessage(); // Fallback to raw message
    }

    // Navigate: details[0].errors[0]
    $error = $decoded['details'][0]['errors'][0] ?? null;
    if ($error === null) {
        return $decoded['message'] ?? $e->getMessage();
    }

    // errorCode is {'authorizationError': 'DEVELOPER_TOKEN_NOT_APPROVED'}
    $errorCode = $error['errorCode'] ?? [];
    $specificCode = reset($errorCode) ?: 'UNKNOWN';
    $errorMessage = $error['message'] ?? '';

    return $specificCode . ($errorMessage ? " - {$errorMessage}" : '');
}
```

### Step 2: Update search() PHPDoc

The `search()` method PHPDoc needs to declare both exception types (matches the new `handleApiException` union return type):

```php
/**
 * Execute a GAQL query against Google Ads API.
 *
 * @param string $query GAQL query to execute
 *
 * @return PagedListResponse Paginated response from the SDK
 *
 * @throws ExternalServiceUnavailableException When API unavailable or rate limited
 * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
 */
public function search(string $query): PagedListResponse
```

### Step 3: Update GoogleAdsClientInterface.php

Add PHPDoc for the new exception type:

```php
use App\Domain\Exceptions\AuthenticationExpiredException;

/**
 * @throws ExternalServiceUnavailableException When API unavailable or rate limited
 * @throws AuthenticationExpiredException When credentials invalid or insufficient permissions
 */
public function verifyConnectivity(): void;
```

### Step 4: Update VerifyApiConnectivityCommand.php

Add import and catch `AuthenticationExpiredException` separately for better user guidance:

```php
use App\Domain\Exceptions\AuthenticationExpiredException;

private function verifyGoogleAds(): bool
{
    $this->info('Verifying Google Ads...');

    try {
        $client = app(GoogleAdsClientInterface::class);
        $client->verifyConnectivity();

        $this->line('  Authentication: OK');
        $this->line('  API Response: Valid');

        return true;
    } catch (AuthenticationExpiredException $e) {
        $this->error('  Authorization Failed: ' . $e->getMessage());
        $this->line('  Check: Developer token access level in Google Ads API Center');
        $this->line('  Hint: Apply for Basic or Standard access at ads.google.com/aw/apicenter');

        return false;
    } catch (Throwable $e) {
        $this->error('  Failed: ' . $e->getMessage());
        $this->line('  Check: Google Ads OAuth credentials and refresh token');

        return false;
    }
}
```

### Step 5: Update Tests for Authorization Error Handling

**Modify existing test** at line 246-260 (`it_preserves_original_api_exception`): Change from `Code::PERMISSION_DENIED` to `Code::INTERNAL` since PERMISSION_DENIED behavior is changing.

**Add new tests** to `GoogleAdsTransportTest.php`:

```php
#[Test]
public function it_throws_authentication_exception_on_permission_denied(): void
{
    $apiException = $this->createApiException(Code::PERMISSION_DENIED);

    $this->mockServiceClient
        ->shouldReceive('search')
        ->andThrow($apiException);

    $this->expectException(AuthenticationExpiredException::class);

    $this->transport->search('SELECT campaign.id FROM campaign');
}

#[Test]
public function it_throws_authentication_exception_on_unauthenticated(): void
{
    $apiException = $this->createApiException(Code::UNAUTHENTICATED);

    $this->mockServiceClient
        ->shouldReceive('search')
        ->andThrow($apiException);

    $this->expectException(AuthenticationExpiredException::class);

    $this->transport->search('SELECT campaign.id FROM campaign');
}

#[Test]
public function it_extracts_google_ads_error_code_from_json_message(): void
{
    $jsonError = json_encode([
        'message' => 'The caller does not have permission',
        'code' => 7,
        'details' => [[
            'errors' => [[
                'errorCode' => ['authorizationError' => 'DEVELOPER_TOKEN_NOT_APPROVED'],
                'message' => 'The developer token is only approved for use with test accounts.'
            ]]
        ]]
    ]);

    $apiException = new ApiException($jsonError, Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

    $this->mockServiceClient
        ->shouldReceive('search')
        ->andThrow($apiException);

    try {
        $this->transport->search('SELECT campaign.id FROM campaign');
        $this->fail('Expected AuthenticationExpiredException');
    } catch (AuthenticationExpiredException $e) {
        $this->assertStringContainsString('DEVELOPER_TOKEN_NOT_APPROVED', $e->getMessage());
    }
}
```

## gRPC Error Codes Reference

| Code | Name | Meaning | Action |
|------|------|---------|--------|
| 7 | PERMISSION_DENIED | Caller lacks permission | → `AuthenticationExpiredException` |
| 16 | UNAUTHENTICATED | Invalid credentials | → `AuthenticationExpiredException` |
| 8 | RESOURCE_EXHAUSTED | Rate limited | → `ExternalServiceUnavailableException` (with retry) |
| 13 | INTERNAL | Server error | → `ExternalServiceUnavailableException` |

## Testing

After implementation:
```bash
# Run tests
make test

# Verify the fix
php -d xdebug.mode=off artisan verify:api googleads
```

## Notes

- **Pattern alignment**: Follows `ShopwiredHttpTransport` pattern - `match` expression, union return type, all handlers return
- The existing `AuthenticationExpiredException` class already supports a custom `$message` parameter
- `handleApiException()` now returns union type `AuthenticationExpiredException|ExternalServiceUnavailableException`
- Existing rate limit logic moves to dedicated `handleRateLimit()` method for consistency
