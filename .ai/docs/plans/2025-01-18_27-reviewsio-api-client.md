# Reviews.io API Client Implementation Plan

## Overview

Create the **first Clean Architecture feature** in alz-core: a Reviews.io HTTP client that establishes architectural patterns for the entire project.

## Architecture Decision

### Location
`App\Infrastructure\Api\ReviewsIoClient`

**Rationale:**
- ✅ Enforced by PHPArkitect rule 8 (API clients must end with "Client")
- ✅ Infrastructure layer = external dependencies (HTTP, databases, third-party APIs)
- ✅ Prevents domain layer coupling to HTTP implementation details

### Execution Model
**Synchronous HTTP client, async execution via Laravel Jobs**

- Client makes blocking HTTP calls (simple, testable)
- Application layer decides sync vs async (Jobs for webhooks/bulk operations)

**Pattern:**
```php
// Synchronous usage (admin dashboard)
$reviews = $reviewsClient->getProductReviews($sku);

// Asynchronous usage (webhook processing)
SendReviewInvitation::dispatch($orderId);
// ↑ Job is async, but internally calls sync client method
```

**Benefits:**
- Single Responsibility: Client handles HTTP, Jobs handle async
- Testability: Easier to test sync HTTP calls with `Http::fake()`
- Flexibility: Same client works for both sync and async use cases
- Laravel Convention: Queues are for business logic, not infrastructure

## Implementation Steps

### 1. Create Directory Structure

```
app/Infrastructure/Api/  (first time creating Infrastructure layer)
```

This establishes the foundation for Clean Architecture in the project.

### 2. Create ReviewsIoClient Class

**File:** `app/Infrastructure/Api/ReviewsIoClient.php`

#### Features

- Use Laravel HTTP client (not raw Guzzle) for modern syntax + built-in retry
- Constructor property promotion (PHP 8.4)
- Validate credentials with webmozart/assert
- Configurable retry logic (3 retries, 100ms delay)
- Specific exceptions (RuntimeException for API failures)
- Strict typing: `declare(strict_types=1);`

#### Methods (based on common review operations)

```php
public function getProductReviews(string $sku, int $page = 1): array
public function sendInvitation(string $email, array $orderData): array
public function getReviewStats(string $sku): array
// Additional methods as needed from old client analysis
```

#### Implementation Pattern

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Webmozart\Assert\Assert;

final class ReviewsIoClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $storeId,
        private readonly string $baseUrl = 'https://api.reviews.co.uk/',
        private readonly int $timeout = 30,
        private readonly int $retryTimes = 3,
        private readonly int $retryDelay = 100,
    ) {
        Assert::notEmpty($apiKey, 'Reviews.io API key cannot be empty');
        Assert::notEmpty($storeId, 'Reviews.io store ID cannot be empty');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->retry($this->retryTimes, $this->retryDelay, throw: false)
            ->withQueryParameters([
                'apikey' => $this->apiKey,
                'store' => $this->storeId,
            ])
            ->timeout($this->timeout);
    }

    public function getProductReviews(string $sku, int $page = 1): array
    {
        $response = $this->http()->get('/product/reviews', [
            'sku' => $sku,
            'page' => $page,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Failed to fetch reviews for SKU {$sku}: " . $response->body()
            );
        }

        return $response->json();
    }

    // Additional methods...
}
```

### 3. Configuration Setup

#### Add to `config/services.php`

```php
'reviewsio' => [
    'api_key' => env('REVIEWSIO_API_KEY'),
    'store' => env('REVIEWSIO_STORE'),
    'base_url' => env('REVIEWSIO_BASE_URL', 'https://api.reviews.co.uk/'),
    'timeout' => env('REVIEWSIO_TIMEOUT', 30),
    'retry_times' => env('REVIEWSIO_RETRY_TIMES', 3),
    'retry_delay' => env('REVIEWSIO_RETRY_DELAY', 100),
],
```

#### Update `.env.example`

```env
REVIEWSIO_API_KEY=
REVIEWSIO_STORE=
```

### 4. Register Singleton in AppServiceProvider

**File:** `app/Providers/AppServiceProvider.php`

```php
public function register(): void
{
    $this->app->singleton(ReviewsIoClient::class, function (Application $app): ReviewsIoClient {
        $config = $app['config']['services.reviewsio'];

        Assert::notEmpty($config['api_key'], 'Reviews.io API key must be configured');
        Assert::notEmpty($config['store'], 'Reviews.io store ID must be configured');

        return new ReviewsIoClient(
            apiKey: $config['api_key'],
            storeId: $config['store'],
            baseUrl: $config['base_url'],
            timeout: $config['timeout'],
            retryTimes: $config['retry_times'],
            retryDelay: $config['retry_delay'],
        );
    });
}
```

**Benefits:**
- Single instance across application (singleton)
- Configuration validated at boot time
- Easy to inject via constructor: `public function __construct(private ReviewsIoClient $client)`

### 5. Comprehensive Testing

**File:** `tests/Unit/Infrastructure/Api/ReviewsIoClientTest.php`

#### Test Coverage

- ✅ Successful API responses
- ✅ API error handling (401, 404, 500)
- ✅ Retry logic verification
- ✅ Query parameter injection (store, apikey)
- ✅ Constructor validation (empty credentials)

#### Example Test

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Api;

use App\Infrastructure\Api\ReviewsIoClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ReviewsIoClientTest extends TestCase
{
    private ReviewsIoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ReviewsIoClient(
            apiKey: 'test_api_key',
            storeId: 'test_store',
        );
    }

    public function test_get_product_reviews_returns_data_on_success(): void
    {
        Http::fake([
            'api.reviews.co.uk/*' => Http::response([
                'reviews' => [
                    ['rating' => 5, 'comment' => 'Great product!'],
                ],
            ], 200),
        ]);

        $result = $this->client->getProductReviews('SKU123');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reviews', $result);
        $this->assertCount(1, $result['reviews']);
        $this->assertSame(5, $result['reviews'][0]['rating']);
    }

    public function test_get_product_reviews_throws_on_api_failure(): void
    {
        Http::fake([
            'api.reviews.co.uk/*' => Http::response(['error' => 'Invalid API key'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch reviews for SKU SKU123');

        $this->client->getProductReviews('SKU123');
    }

    public function test_constructor_validates_empty_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reviews.io API key cannot be empty');

        new ReviewsIoClient(apiKey: '', storeId: 'test_store');
    }
}
```

**Testing Strategy:**
- Use `Http::fake()` for mocking - no real HTTP calls
- Test both success AND failure paths (Infection mutation testing requirement)
- Verify actual data values, not just `assertNotNull()` (avoids weak assertions)

### 6. Quality Validation

#### Run Full Linting Suite

```bash
make lint-full  # Pint + PHPStan + PHP Insights + PHPArkitect
make test       # Pest tests
make infection  # Mutation testing - validates test quality
```

#### PHPArkitect Validation

The architecture enforcement will automatically verify:
- ✅ Client lives in `App\Infrastructure\Api\`
- ✅ Class name ends with "Client"
- ✅ No presentation layer dependencies

## Questions to Answer During Implementation

### 1. What Reviews.io API endpoints do you need?
- Need to see old client usage or API documentation
- Will determine which methods to implement first
- **Action:** Analyze legacy PHP client usage patterns

### 2. API response structure?
- Return raw arrays or create DTOs?
- **Recommendation:** Start with arrays (thin SDK philosophy), add DTOs later if needed
- Matches project directive: "Thin SDK: E-commerce API package stays simple, Laravel handles logic"

### 3. Error handling strategy?
- Throw exceptions or return null/false?
- **Recommendation:** Throw RuntimeException (caller decides retry logic)
- Follows modern PHP standards: Use specific SPL exceptions

## Success Criteria

- ✅ All 4 linters pass (Pint, PHPStan max, PHP Insights, PHPArkitect)
- ✅ Tests achieve >90% coverage
- ✅ Infection mutation testing passes
- ✅ Client registered as singleton in Laravel container
- ✅ Can inject via constructor: `__construct(ReviewsIoClient $client)`
- ✅ Establishes Clean Architecture pattern for future features

## Next Steps After Client

This client is the foundation. After implementation:

1. **Create Application layer Use Case** (e.g., `SyncProductReviewsUseCase`)
2. **Create Laravel Job for async operations** (`SendReviewInvitationJob`)
3. **Add to webhook processing pipeline**
4. **Cache review data in Redis**

## Why This Matters

This is the **first real feature** establishing the Clean Architecture pattern for the entire project.

**Current state:** Only 15 PHP files in `app/` directory - mostly infrastructure (git hooks, middleware, service providers). No business features implemented yet.

**Advantages of implementing this first:**
- ✅ Clean slate - establish patterns correctly from day one
- ✅ PHPArkitect will guide you immediately (easy to fix on small codebase)
- ✅ No legacy patterns to refactor
- ✅ Educational - violations teach architecture in real-time

**This client proves:**
1. Clean Architecture works in this Laravel codebase
2. PHPArkitect enforcement catches violations early
3. Modern PHP 8.4 features integrate smoothly
4. Strict linting standards are achievable

## References

- **Legacy implementation:** See old PHP Guzzle-based client (provided by user)
- **Laravel HTTP client docs:** https://laravel.com/docs/12.x/http-client
- **PHPArkitect rules:** `phparkitect.php` lines 191-195 (API client naming)
- **Project standards:** `CLAUDE.md` sections on Modern PHP Standards and Code Quality