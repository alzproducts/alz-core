# Domain Layer Exception Handling

## Purpose
Domain exceptions represent **business concepts**: service unavailable, authentication expired, insufficient stock, invalid state. They express what the business understands, not technical details.

## What Belongs in Domain

### ✅ Business Rule Violations
```php
class InsufficientStockException extends DomainException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Requested {$requested}, only {$available} available");
    }
}
```

### ✅ External Service Failures (Business Concept)
```php
class ExternalServiceUnavailableException extends DomainException
{
    public function __construct(
        public readonly string $serviceName,
        public readonly int $retryAfter = 60,
    ) {
        parent::__construct("{$serviceName} is unavailable");
    }
}
```

**API Contract Violations** (programming errors):
- `InvalidApiResponseException` - External API response doesn't match expected structure (Spatie DTO validation failed)
- Has `serviceName` property
- Signals: "Code needs updating for new API version"
- Should NOT retry (permanent until code changes)
```php
final class InvalidApiResponseException extends \DomainException
{
    public function __construct(
        public readonly string $serviceName,
        string $message = 'API response validation failed',
    ) {
        parent::__construct($message);
    }
}
```

### ✅ Authentication/Authorization
```php
class AuthenticationExpiredException extends DomainException
{
    public function __construct(public readonly string $serviceName)
    {
        parent::__construct("Authentication expired for {$serviceName}");
    }
}
```

### ✅ Domain State Violations
```php
class OrderCannotBeCancelledException extends DomainException
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $currentStatus,
    ) {
        parent::__construct("Order {$orderId} cannot be cancelled (status: {$currentStatus})");
    }
}
```

## Exception Design Patterns

### Pattern: Readonly Value Objects
```php
final class PaymentFailedException extends DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $transactionId = null,
    ) {
        parent::__construct("Payment failed: {$reason}");
    }
}
```

### Pattern: Named Constructors
```php
final class ApiValidationException extends DomainException
{
    private function __construct(
        public readonly array $errors,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromFailedRecords(array $failedRecords): self
    {
        return new self($failedRecords, count($failedRecords) . " records failed validation");
    }
}
```

## What NOT to Put in Domain

### ❌ Generic Wrappers
```php
// WRONG: Too generic
class SyncFailedException extends DomainException {}
class ApiErrorException extends DomainException {}

// RIGHT: Specific business state
class ExternalServiceUnavailableException extends DomainException {}
class DataValidationFailedException extends DomainException {}
```

### ❌ Framework Dependencies
```php
// WRONG: Couples to Laravel
class OrderException extends \Illuminate\Http\Exceptions\HttpResponseException {}

// RIGHT: Framework-agnostic
class OrderException extends \DomainException {}
```

### ❌ Infrastructure Details
```php
// WRONG: Knows about HTTP/rate limiting
class RateLimitException extends DomainException {}
class HttpTimeoutException extends DomainException {}

// RIGHT: Business concept with retry info
class ExternalServiceUnavailableException extends DomainException
{
    public function __construct(
        public readonly string $serviceName,
        public readonly int $retryAfter = 60,
    ) {}
}
```

## Domain Never Catches

Domain **only throws**, never catches:
```php
// ✅ CORRECT
class OrderService
{
    public function cancelOrder(int $orderId): void
    {
        $order = $this->orders->find($orderId);
        
        if ($order->status === OrderStatus::Shipped) {
            throw new OrderCannotBeCancelledException($orderId, 'shipped');
        }
        
        $order->cancel();
    }
}

// ❌ WRONG - Domain shouldn't catch
class OrderService
{
    public function cancelOrder(int $orderId): void
    {
        try {
            $order->cancel();
        } catch (OrderException $e) {
            Log::error('Cancel failed');
            throw $e;
        }
    }
}
```

## Assertions vs Exceptions

- **Assertions**: Programming errors (developer mistakes)
- **Exceptions**: Business rule violations (runtime conditions)
```php
class CampaignMetrics
{
    public function __construct(
        public readonly int $campaignId,
        public readonly float $costInDollars,
    ) {
        // Assertions: Developer passed invalid data
        Assert::greaterThan($campaignId, 0);
        Assert::greaterThanEq($costInDollars, 0);
    }
}

class OrderService
{
    public function applyDiscount(Order $order, float $amount): void
    {
        // Exception: Business rule violation
        if ($amount > $order->total) {
            throw new InvalidDiscountException($amount, $order->total);
        }
    }
}
```

## PHPStan Documentation
```php
interface GoogleAdsClientInterface
{
    /**
     * @return array<int, CampaignMetrics>
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     */
    public function getDailyCampaignMetrics(string $date): array;
}
```

## Checklist: Is This a Domain Exception?

- [ ] Does business understand this concept?
- [ ] Is it framework-agnostic?
- [ ] Is it specific (not "failed" but "authentication expired")?
- [ ] Does it include business context (IDs, amounts)?
- [ ] Is it readonly/immutable?
- [ ] Does it extend `\DomainException` or `\LogicException`?

If "no" to any, it probably belongs in Infrastructure or is too generic.
