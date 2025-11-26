# Plan: Add Shopwired API Client

## Overview

Add Shopwired API client following the established template pattern (Mixpanel/ReviewsIO).

## API Details

- **Base URL**: `https://api.ecommerceapi.uk/v1`
- **Auth**: HTTP Basic Auth (same as Mixpanel)
  - `SHOPWIRED_API_KEY` = Basic Auth username
  - `SHOPWIRED_API_SECRET` = Basic Auth password
- **Verify endpoint**: `GET /business` (returns 200 on success)
- **Env vars**: `SHOPWIRED_API_KEY`, `SHOPWIRED_API_SECRET`

## Files to Create

### 1. Interface (Application Layer)

`app/Application/Contracts/ShopwiredClientInterface.php`

```php
interface ShopwiredClientInterface
{
    /**
     * Verify API connectivity and authentication.
     * @throws ExternalServiceUnavailableException
     */
    public function verifyConnectivity(): void;
}
```

### 2. Config Value Object

`app/Infrastructure/Shopwired/ShopwiredConfig.php`

- Readonly class with fail-fast validation
- Properties: `apiKey`, `apiSecret`, `baseUrl`, `timeout`, `retryTimes`, `retryDelay`
- Constant: `DEFAULT_BASE_URL = 'https://api.ecommerceapi.uk/v1'`

### 3. HTTP Transport

`app/Infrastructure/Shopwired/ShopwiredHttpTransport.php`

- Uses `Http::withBasicAuth($apiKey, $apiSecret)` (same pattern as Mixpanel)
- Retry strategy via `ApiRetryStrategy::defaultRetry()`
- Exception translation to `ExternalServiceUnavailableException`

### 4. Client

`app/Infrastructure/Shopwired/ShopwiredClient.php`

- Implements `ShopwiredClientInterface`
- `verifyConnectivity()`: Calls `GET /business` endpoint with `retry: false`

### 5. Factory

`app/Infrastructure/Shopwired/ShopwiredClientFactory.php`

- Static `create()` method
- Reads config, validates, wires dependencies

### 6. Service Provider

`app/Providers/ShopwiredServiceProvider.php`

- Deferred provider binding interface to factory

### 7. Config File

`config/shopwired.php`

```php
return [
    'api_key' => env('SHOPWIRED_API_KEY'),
    'api_secret' => env('SHOPWIRED_API_SECRET'),
    'timeout' => 30,
    'retry_times' => 3,
    'retry_delay' => 100,
];
```

## Files to Modify

### 1. Register Provider

`bootstrap/providers.php` - Add `ShopwiredServiceProvider::class`

### 2. Update Verify Command

`app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php`

- Add `'shopwired'` case in match expression
- Add `verifyShopwired()` private method (same pattern as others)
- Update `'all'` case to include shopwired

### 3. Environment Example

`.env.example` - Add placeholder entries:

```
SHOPWIRED_API_KEY=
SHOPWIRED_API_SECRET=
```

### 4. Deptrac Config

`deptrac.yaml` - **No changes needed.** Shopwired client uses only Laravel HTTP facade which is already whitelisted for Infrastructure layer.

## Implementation Order

1. Create config file (`config/shopwired.php`)
2. Update `.env.example` with placeholders
3. Create interface (`ShopwiredClientInterface`)
4. Create config value object (`ShopwiredConfig`)
5. Create HTTP transport (`ShopwiredHttpTransport`)
6. Create client (`ShopwiredClient`)
7. Create factory (`ShopwiredClientFactory`)
8. Create service provider (`ShopwiredServiceProvider`)
9. Register provider in `bootstrap/providers.php`
10. Update `VerifyApiConnectivityCommand`
11. Run linters (`make lint`)
12. Write tests

## Testing

Create `tests/Feature/Infrastructure/Api/ShopwiredClientTest.php`:

- Test successful connectivity verification
- Test HTTP Basic Auth headers sent correctly
- Test exception translation (4xx, 5xx, connection errors)
- Test retry behavior disabled for verify

## Reference Files

- `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php` - HTTP Basic Auth pattern
- `app/Infrastructure/ReviewsIo/ReviewsIoClient.php` - verifyConnectivity pattern
- `app/Providers/ReviewsIoServiceProvider.php` - Deferred provider pattern

---

# Phase 2: Retry Strategies, Caching & Payment Methods

## Overview

Enhance the ShopWired client with configurable retry strategies, basic caching, and the `listPaymentMethods` endpoint. ShopWired's 60/min leaky bucket rate limit is shared with the legacy server, requiring conservative background retries.

## Part 1: RetryStrategy Enum

**File**: `app/Infrastructure/Shopwired/RetryStrategy.php` (NEW)

```php
enum RetryStrategy: string
{
    case Background = 'background';  // Patient: 5 attempts, exponential backoff
    case Urgent = 'urgent';          // Fast-fail: 2 attempts, 100ms fixed

    public function times(): int
    {
        return match ($this) {
            self::Background => 5,
            self::Urgent => 2,
        };
    }

    public function baseDelayMs(): int
    {
        return match ($this) {
            self::Background => 500,   // Exponential: 500ms, 1s, 2s, 4s, 8s
            self::Urgent => 100,       // Fixed 100ms
        };
    }

    public function useExponentialBackoff(): bool
    {
        return $this === self::Background;
    }
}
```

## Part 2: Transport Updates

**File**: `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` (MODIFY)

- Replace `bool $retry` with `?RetryStrategy $strategy` parameter
- Add `buildSleepClosure()` for exponential backoff support
- Keep `bool $retry` for backwards compatibility with `verifyConnectivity()`

```php
private function buildSleepClosure(RetryStrategy $strategy): Closure
{
    $baseMs = $strategy->baseDelayMs();

    if (!$strategy->useExponentialBackoff()) {
        return static fn(int $attempt, Exception $e): int => $baseMs;
    }

    // Exponential: 500ms, 1s, 2s, 4s, 8s (capped at 16s)
    return static fn(int $attempt, Exception $e): int =>
        min($baseMs * (2 ** ($attempt - 1)), 16000);
}
```

## Part 3: PaymentMethod Infrastructure DTO

**File**: `app/Infrastructure/Shopwired/Responses/PaymentMethod.php` (NEW)

```php
#[MapInputName(SnakeCaseMapper::class)]
final class PaymentMethod extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}
```

## Part 4: PaymentMethod Domain Value Object

**File**: `app/Domain/Order/PaymentMethod.php` (NEW)

```php
final readonly class PaymentMethod
{
    public function __construct(
        public string $name,
    ) {}
}
```

Note: ID is external system concern, not needed in domain.

## Part 5: CacheTimes Trait

**File**: `app/Application/Support/CacheTimes.php` (NEW)

```php
trait CacheTimes
{
    protected const int ONE_MINUTE = 60;
    protected const int FIVE_MINUTES = 300;
    protected const int ONE_HOUR = 3600;
    protected const int ONE_DAY = 86400;
    protected const int SEVEN_DAYS = 604800;
    protected const int THIRTY_DAYS = 2592000;
}
```

## Part 6: Client Interface Update

**File**: `app/Application/Contracts/ShopwiredClientInterface.php` (MODIFY)

```php
/**
 * List available payment methods.
 *
 * @return list<PaymentMethod>
 * @throws ExternalServiceUnavailableException When API unavailable
 */
public function listPaymentMethods(): array;
```

## Part 7: Client Implementation

**File**: `app/Infrastructure/Shopwired/ShopwiredClient.php` (MODIFY)

- Add endpoint constant: `private const string ENDPOINT_PAYMENT_METHODS = 'payment-methods';`
- Add `listPaymentMethods()` implementation
- Copy `parseArrayResponse()` and `logParsingFailure()` from ReviewsIoClient

## Part 8: Caching Service

**File**: `app/Application/Shopwired/Services/CachingShopwiredService.php` (NEW)

```php
use Psr\SimpleCache\CacheInterface;

final readonly class CachingShopwiredService
{
    use CacheTimes;

    public const string CACHE_PREFIX = 'shopwired';
    private const string KEY_PAYMENT_METHODS = self::CACHE_PREFIX . ':payment-methods';

    public function __construct(
        private ShopwiredClientInterface $client,
        private CacheInterface $cache, // PSR-16
    ) {}

    public function getPaymentMethods(): array
    {
        $cached = $this->cache->get(self::KEY_PAYMENT_METHODS);
        if ($cached !== null) {
            return $cached;
        }

        $methods = $this->client->listPaymentMethods();
        $this->cache->set(self::KEY_PAYMENT_METHODS, $methods, self::SEVEN_DAYS);

        return $methods;
    }

    public function invalidatePaymentMethods(): void
    {
        $this->cache->delete(self::KEY_PAYMENT_METHODS);
    }

    public function invalidateAll(): void
    {
        $this->invalidatePaymentMethods();
    }
}
```

## Part 9: Cache Clear Command

**File**: `app/Presentation/Console/Commands/ShopwiredCacheClearCommand.php` (NEW)

```php
final class ShopwiredCacheClearCommand extends Command
{
    protected $signature = 'shopwired:cache-clear {resource?}';
    protected $description = 'Clear ShopWired API cache';

    public function handle(CachingShopwiredService $service): int
    {
        $resource = $this->argument('resource') ?? 'all';

        match ($resource) {
            'payment-methods', 'all' => $service->invalidatePaymentMethods(),
            default => $this->error("Unknown resource: {$resource}"),
        };

        $this->info("ShopWired cache cleared: {$resource}");
        return self::SUCCESS;
    }
}
```

## Phase 2 Testing

### Unit Tests (NEW)

- `tests/Unit/Infrastructure/Shopwired/RetryStrategyTest.php` - Enum values
- `tests/Unit/Infrastructure/Shopwired/Responses/PaymentMethodTest.php` - DTO
- `tests/Unit/Application/Shopwired/CachingShopwiredServiceTest.php` - Caching
- `tests/Unit/Domain/Order/PaymentMethodTest.php` - Value object

### Feature Tests (MODIFY/NEW)

- `tests/Feature/Infrastructure/Api/ShopwiredClientTest.php` - Add listPaymentMethods tests
- `tests/Feature/Presentation/Console/ShopwiredCacheClearCommandTest.php` - Command tests

## Phase 2 Files Summary

| File | Action | Purpose |
|------|--------|---------|
| `Infrastructure/Shopwired/RetryStrategy.php` | NEW | Enum for retry modes |
| `Infrastructure/Shopwired/ShopwiredHttpTransport.php` | MODIFY | Accept strategy param |
| `Infrastructure/Shopwired/Responses/PaymentMethod.php` | NEW | Infrastructure DTO |
| `Infrastructure/Shopwired/ShopwiredClient.php` | MODIFY | Add listPaymentMethods |
| `Domain/Order/PaymentMethod.php` | NEW | Domain value object |
| `Application/Support/CacheTimes.php` | NEW | TTL constants trait |
| `Application/Contracts/ShopwiredClientInterface.php` | MODIFY | Add method signature |
| `Application/Shopwired/Services/CachingShopwiredService.php` | NEW | Caching wrapper |
| `Presentation/Console/Commands/ShopwiredCacheClearCommand.php` | NEW | Cache invalidation |

## Future Work (Not This PR)

1. **Webhook Integration** - Automatic cache invalidation via ShopWired webhooks
2. **More Endpoints** - Products, Orders, etc. following same DTO pattern
3. **Cache Tags** - When Redis confirmed, migrate to `Cache::tags()` for granular invalidation
