# Infrastructure Layer

## Eloquent Repositories

**All new repositories MUST:**
1. Define interface extending `RepositoryWriteInterface` in `Application/Contracts/`
2. Create implementation extending `AbstractEloquentRepository`

---

## Exception Handling

Infrastructure **always catches** SDK exceptions and **translates** to Domain exceptions. This is where technical → business translation happens.

## Core Pattern: Catch and Translate
```php
class GoogleAdsClient implements GoogleAdsClientInterface
{
    public function getDailyCampaignMetrics(string $date): array
    {
        try {
            return $this->client->searchStream($this->buildQuery($date));
            
        } catch (ApiException $e) {
            // 1. Log technical details
            Log::error('Google Ads API error', [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
            
            // 2. Translate to Domain exception
            if ($this->isRateLimitError($e)) {
                throw new ExternalServiceUnavailableException('Google Ads', 60);
            }
            
            if ($this->isAuthError($e)) {
                throw new AuthenticationExpiredException('Google Ads');
            }
            
            throw new ExternalServiceUnavailableException('Google Ads');
        }
    }
    
    private function isRateLimitError(ApiException $e): bool
    {
        return str_contains($e->getMessage(), 'RATE_LIMIT_EXCEEDED');
    }
}
```

## HTTP Client Pattern
```php
class MixpanelClient implements MixpanelClientInterface
{
    public function importBatch(array $events): void
    {
        try {
            $response = Http::timeout(30)->post('/import', $events);
            
            if ($response->status() === 429) {
                $retryAfter = (int) $response->header('Retry-After', 60);
                throw new ExternalServiceUnavailableException('Mixpanel', $retryAfter);
            }
            
            if ($response->status() === 401) {
                throw new AuthenticationExpiredException('Mixpanel');
            }
            
            if ($response->failed()) {
                throw new ExternalServiceUnavailableException('Mixpanel');
            }
            
        } catch (ConnectionException $e) {
            Log::error('Connection failed', ['error' => $e->getMessage()]);
            throw new ExternalServiceUnavailableException('Mixpanel', 120);
        }
    }
}
```

## Data Validation Failures (Spatie DTOs)

When Spatie Data throws `ValidationException`, the API contract changed. This is a **programming error**, not a transient failure.

### Pattern: Nested Try-Catch
```php
// GoogleAdsClient.php
try {
    $response = $this->client->searchStream($query);
    
    foreach ($response->iterateAllElements() as $row) {
        try {
            // Spatie DTO validates API response structure
            $data = GoogleAdsCampaignData::from($row);
            $metrics[] = CampaignMetrics::fromDto($data);
            
        } catch (ValidationException $e) {
            // API contract violation - log raw response for debugging
            Log::critical('API response validation failed', [
                'service' => 'Google Ads',
                'errors' => $e->errors(),
                'raw_response' => $row, // CRITICAL: needed to fix code
            ]);
            
            throw new InvalidApiResponseException(
                'Google Ads',
                'API response structure changed. Code needs updating.'
            );
        }
    }
    
    return $metrics;
    
} catch (ApiException $e) {
    // Different exception path: network/rate limit errors
    Log::error('Google Ads API error', ['code' => $e->getCode()]);
    if ($this->isRateLimitError($e)) {
        throw new ExternalServiceUnavailableException('Google Ads', 60);
    }
    throw new ExternalServiceUnavailableException('Google Ads');
}
```

**Key Points:**
- Nested try-catch: inner for validation, outer for API errors
- Log at **CRITICAL** level (code needs immediate update)
- Include raw response (you'll need this to fix the DTO)
- Don't retry - this is permanent until code changes

## Critical Rules

### ✅ Always Log Before Translating
SDK details won't exist in Domain exception - log them first.

### ✅ Never Let SDK Exceptions Escape
```php
// WRONG
public function fetch(): array
{
    return $this->client->get(); // ApiException escapes
}

// RIGHT
public function fetch(): array
{
    try {
        return $this->client->get();
    } catch (ApiException $e) {
        throw new ExternalServiceUnavailableException('Service');
    }
}
```

### ✅ Never Return Empty to Hide Failures
```php
// WRONG
try {
    return $this->client->fetch();
} catch (ApiException $e) {
    return []; // Hides failure
}

// RIGHT
try {
    return $this->client->fetch();
} catch (ApiException $e) {
    throw new ExternalServiceUnavailableException('Service', 60);
}
```

## Configuration Validation

Use `RuntimeException` for config errors (programming mistakes):
```php
class GoogleAdsClientFactory
{
    public static function create(): GoogleAdsClient
    {
        $clientId = config('google-ads.client_id');

        if (!is_string($clientId) || $clientId === '') {
            throw new RuntimeException('GOOGLE_ADS_CLIENT_ID not configured');
        }

        return new GoogleAdsClient(/* ... */);
    }
}
```

## Spatie LaravelData (External API Parsing)

Use Spatie DTOs in Infrastructure to parse external API responses:

```php
#[MapInputName(SnakeCaseMapper::class)]
final class ExternalApiResponse extends Data {
    public function __construct(
        public readonly string $orderId,
        public readonly float $totalAmount,
    ) {}
}
```

**Rule**: ❌ NOT allowed in Domain layer — Domain must stay framework-independent.

## Bulk Inserts

Bulk `insert()` bypasses Eloquent timestamps - manually add `created_at`/`updated_at` in mapper.

## Testing
```php
test('translates rate limit to domain exception', function () {
    $mockClient = Mockery::mock(SdkClient::class);
    $mockClient->shouldReceive('searchStream')
        ->andThrow(new ApiException('RATE_LIMIT_EXCEEDED'));
    
    $client = new GoogleAdsClient($mockClient, '123');
    
    expect(fn() => $client->getDailyCampaignMetrics('2024-11-18'))
        ->toThrow(ExternalServiceUnavailableException::class);
});
```

## Checklist

- [ ] All SDK exceptions caught at boundary
- [ ] Technical details logged before translation
- [ ] Translated to specific Domain exceptions
- [ ] Rate limits include retryAfter value
- [ ] Auth failures throw AuthenticationExpiredException
- [ ] No SDK exceptions escape to Application
- [ ] No empty arrays hiding failures

**Golden Rule**: Nothing leaves Infrastructure without a Domain exception passport.
