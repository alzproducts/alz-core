# ReviewsIoClient Refactoring Plan

## Overview

Refactor `ReviewsIoClient` to establish the **template pattern** for all future API clients. Includes full separation of concerns (Transport, Config, Interface, Factory) and a multi-client verification command.

## Scope

### New Files (6)
| File | Purpose |
|------|---------|
| `app/Application/Contracts/ReviewsIoClientInterface.php` | Interface for DIP compliance |
| `app/Infrastructure/ReviewsIo/ReviewsIoConfig.php` | Configuration value object |
| `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php` | HTTP transport layer |
| `app/Infrastructure/ReviewsIo/ReviewsIoClientFactory.php` | Factory for instantiation |
| `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php` | Multi-client verification |
| `tests/Feature/Presentation/Console/Commands/VerifyApiConnectivityCommandTest.php` | Command tests |

### Modified Files (3)
| File | Changes |
|------|---------|
| `app/Infrastructure/ReviewsIo/ReviewsIoClient.php` | Implement interface, inject transport, fix bugs |
| `app/Providers/ReviewsIoServiceProvider.php` | Bind interface via factory |
| `tests/Feature/Infrastructure/Api/ReviewsIoClientTest.php` | Update setup, move validation tests |

### New Test File (1)
| File | Purpose |
|------|---------|
| `tests/Unit/Infrastructure/ReviewsIo/ReviewsIoConfigTest.php` | Constructor validation tests (moved from client) |

---

## Implementation Order

### Phase 1: Verification Command (CREATE FIRST)

**Purpose**: Test current implementation works before ANY changes.

**File**: `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Infrastructure\ReviewsIo\ReviewsIoClient;
use Illuminate\Console\Command;
use Throwable;

final class VerifyApiConnectivityCommand extends Command
{
    protected $signature = 'verify:api {client : The API client to verify (reviewsio, mixpanel, googleads, all)}';
    protected $description = 'Verify connectivity and authentication with external API services';

    public function handle(): int
    {
        $client = $this->argument('client');

        $results = match ($client) {
            'reviewsio' => ['reviewsio' => $this->verifyReviewsIo()],
            'mixpanel' => ['mixpanel' => $this->verifyMixpanel()],
            'googleads' => ['googleads' => $this->verifyGoogleAds()],
            'all' => [
                'reviewsio' => $this->verifyReviewsIo(),
                'mixpanel' => $this->verifyMixpanel(),
                'googleads' => $this->verifyGoogleAds(),
            ],
            default => null,
        };

        if ($results === null) {
            $this->error("Unknown client: {$client}");
            $this->line('Available: reviewsio, mixpanel, googleads, all');
            return self::FAILURE;
        }

        $this->newLine();
        $failed = array_filter($results, static fn(bool $success) => !$success);

        if ($failed === []) {
            $this->info('All API clients verified successfully');
            return self::SUCCESS;
        }

        $this->error('Some API clients failed: ' . implode(', ', array_keys($failed)));
        return self::FAILURE;
    }

    private function verifyReviewsIo(): bool
    {
        $this->info('Verifying Reviews.io...');

        try {
            // Note: Initially uses concrete class, will update to interface in Phase 8
            $client = app(ReviewsIoClient::class);
            $result = $client->getProductRatingBatch('VERIFY-CONNECTIVITY-TEST');

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid (returned ' . count($result) . ' ratings)');
            return true;
        } catch (Throwable $e) {
            $this->error('  Failed: ' . $e->getMessage());
            $this->line('  Check: REVIEWS_IO_API_KEY and REVIEWS_IO_STORE_ID in .env');
            return false;
        }
    }

    private function verifyMixpanel(): bool
    {
        $this->info('Verifying Mixpanel...');

        try {
            $client = app(MixpanelClientInterface::class);
            $client->importCampaigns([]);

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid');
            return true;
        } catch (Throwable $e) {
            $this->error('  Failed: ' . $e->getMessage());
            $this->line('  Check: MIXPANEL_* credentials in .env');
            return false;
        }
    }

    private function verifyGoogleAds(): bool
    {
        $this->info('Verifying Google Ads...');

        try {
            $client = app(GoogleAdsClientInterface::class);
            $campaigns = $client->getCampaigns();

            $this->line('  Authentication: OK');
            $this->line('  API Response: Valid (found ' . count($campaigns) . ' campaigns)');
            return true;
        } catch (Throwable $e) {
            $this->error('  Failed: ' . $e->getMessage());
            $this->line('  Check: Google Ads OAuth credentials and refresh token');
            return false;
        }
    }
}
```

**Checkpoint**:
```bash
./vendor/bin/sail artisan verify:api reviewsio
```

---

### Phase 2: Fix Existing Bugs (Non-Breaking)

#### Bug 1: Wrong Exception Type (Line 173)

**Current** (wrong):
```php
if (!is_array($data)) {
    throw new ReviewsIoApiException('Reviews.io API invalid response: Expected array response');
}
```

**Fixed**:
```php
if (!is_array($data)) {
    Log::critical('Reviews.io API returned non-array response', [
        'response_type' => gettype($data),
        'raw_response' => $data,
    ]);

    throw new InvalidReviewsIoResponseException(
        message: 'Reviews.io API returned non-array response',
    );
}
```

#### Bug 2: Missing Logging for Non-429 Errors (Lines 136-151)

**Current**: Only logs inside 429 check.

**Fixed**: Log ALL request failures:
```php
catch (RequestException $e) {
    $status = $e->response?->status();
    $retryAfter = ($status === 429) ? (int) $e->response?->header('Retry-After') : null;

    Log::error('Reviews.io API request failed', [
        'status' => $status,
        'error' => $e->getMessage(),
        'retry_after' => $retryAfter,
    ]);

    throw new ExternalServiceUnavailableException('Reviews.io', $retryAfter, $e);
}
```

**Checkpoint**:
```bash
./vendor/bin/sail artisan verify:api reviewsio
./vendor/bin/sail artisan test --filter=ReviewsIoClientTest
```

---

### Phase 3: Create Interface (Non-Breaking Addition)

**File**: `app/Application/Contracts/ReviewsIoClientInterface.php`

```php
<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\ReviewsIo\Exceptions\InvalidReviewsIoResponseException;
use App\Infrastructure\ReviewsIo\Responses\Rating;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\DataCollection;

interface ReviewsIoClientInterface
{
    /**
     * Get product reviews by SKU in batch.
     *
     * @param string|array<string> $skus Single SKU or array of SKUs (max 100)
     * @return DataCollection<int, Rating>
     *
     * @throws ExternalServiceUnavailableException When API unavailable or rate limited
     * @throws InvalidReviewsIoResponseException When response structure is invalid
     * @throws ValidationException When SKUs are invalid
     */
    public function getProductRatingBatch(array|string $skus): DataCollection;
}
```

Then add `implements ReviewsIoClientInterface` to `ReviewsIoClient`.

**Checkpoint**: Same as Phase 2.

---

### Phase 4: Create Config Value Object (Non-Breaking Addition)

**File**: `app/Infrastructure/ReviewsIo/ReviewsIoConfig.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable configuration for Reviews.io API client.
 * Template pattern for future API client configs.
 */
final readonly class ReviewsIoConfig
{
    public const int MAX_BATCH_SIZE = 100;
    public const string SKU_DELIMITER = ';';

    private const int MAX_TIMEOUT_SECONDS = 300;
    private const int MAX_RETRY_ATTEMPTS = 10;
    private const int MAX_RETRY_DELAY_MS = 5000;

    public function __construct(
        public string $apiKey,
        public string $storeId,
        public string $baseUrl = 'https://api.reviews.co.uk/',
        public int $timeout = 30,
        public int $retryTimes = 3,
        public int $retryDelay = 100,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('Reviews.io API key cannot be empty');
        }

        if ($storeId === '') {
            throw new RuntimeException('Reviews.io store ID cannot be empty');
        }

        if ($timeout < 1 || $timeout > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(
                sprintf('Timeout must be between 1-%d seconds, got %d', self::MAX_TIMEOUT_SECONDS, $timeout),
            );
        }

        if ($retryTimes < 0 || $retryTimes > self::MAX_RETRY_ATTEMPTS) {
            throw new InvalidArgumentException(
                sprintf('Retry times must be between 0-%d, got %d', self::MAX_RETRY_ATTEMPTS, $retryTimes),
            );
        }

        if ($retryDelay < 0 || $retryDelay > self::MAX_RETRY_DELAY_MS) {
            throw new InvalidArgumentException(
                sprintf('Retry delay must be between 0-%dms, got %d', self::MAX_RETRY_DELAY_MS, $retryDelay),
            );
        }
    }
}
```

**Checkpoint**: Same tests still pass (config not integrated yet).

---

### Phase 5: Create HTTP Transport (Non-Breaking Addition)

**File**: `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Support\ApiRetryStrategy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP transport layer for Reviews.io API.
 * Template pattern for future API client transports.
 *
 * Handles: auth, retry, timeout, exception translation.
 * Does NOT handle: validation, response parsing.
 */
final readonly class ReviewsIoHttpTransport
{
    private const string SERVICE_NAME = 'Reviews.io';

    public function __construct(
        private ReviewsIoConfig $config,
    ) {}

    /**
     * @param array<string, mixed> $queryParams
     * @throws ExternalServiceUnavailableException
     */
    public function get(string $endpoint, array $queryParams = []): Response
    {
        try {
            return $this->createRequest()
                ->get($endpoint, $queryParams)
                ->throw();
        } catch (RequestException $e) {
            throw $this->handleRequestException($e);
        } catch (ConnectionException $e) {
            throw $this->handleConnectionException($e);
        }
    }

    private function createRequest(): PendingRequest
    {
        return Http::baseUrl($this->config->baseUrl)
            ->withQueryParameters([
                'apikey' => $this->config->apiKey,
                'store' => $this->config->storeId,
            ])
            ->retry(
                times: $this->config->retryTimes,
                sleepMilliseconds: $this->config->retryDelay,
                when: ApiRetryStrategy::defaultRetry(),
            )
            ->timeout($this->config->timeout);
    }

    private function handleRequestException(RequestException $e): ExternalServiceUnavailableException
    {
        $status = $e->response?->status();
        $retryAfter = ($status === 429) ? (int) $e->response?->header('Retry-After') : null;

        Log::error(self::SERVICE_NAME . ' API request failed', [
            'status' => $status,
            'error' => $e->getMessage(),
            'retry_after' => $retryAfter,
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, $retryAfter, $e);
    }

    private function handleConnectionException(ConnectionException $e): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API connection failed', [
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
```

**Checkpoint**: Same tests still pass (transport not integrated yet).

---

### Phase 6: Refactor Client (Internal Breaking Change)

**Changes to `ReviewsIoClient.php`**:

1. Change constructor from 5 scalar params to single `ReviewsIoHttpTransport`
2. Remove `http()` method (delegated to transport)
3. Remove constructor validation (moved to `ReviewsIoConfig`)
4. Use `ReviewsIoConfig::MAX_BATCH_SIZE` and `SKU_DELIMITER` constants

```php
final readonly class ReviewsIoClient implements ReviewsIoClientInterface
{
    public function __construct(
        private ReviewsIoHttpTransport $transport,
    ) {}

    public function getProductRatingBatch(array|string $skus): DataCollection
    {
        $skuArray = is_array($skus) ? $skus : [$skus];

        $validated = Validator::make(
            ['skus' => $skuArray],
            [
                'skus' => ['required', 'array', 'min:1', 'max:' . ReviewsIoConfig::MAX_BATCH_SIZE],
                'skus.*' => ['required', 'string', 'min:1', 'max:50', new ValidSku()],
            ],
        )->validate();

        /** @var array<string> $validatedSkus */
        $validatedSkus = $validated['skus'];

        $response = $this->transport->get('product/rating-batch', [
            'sku' => implode(ReviewsIoConfig::SKU_DELIMITER, $validatedSkus),
        ]);

        return $this->parseResponse($response->json());
    }

    private function parseResponse(mixed $data): DataCollection
    {
        if (!is_array($data)) {
            Log::critical('Reviews.io API returned non-array response', [
                'response_type' => gettype($data),
                'raw_response' => $data,
            ]);

            throw new InvalidReviewsIoResponseException(
                message: 'Reviews.io API returned non-array response',
            );
        }

        try {
            return Rating::collect($data, DataCollection::class);
        } catch (CannotCreateData $e) {
            Log::critical('Reviews.io API response validation failed', [
                'error' => $e->getMessage(),
                'raw_response' => $data,
            ]);

            throw new InvalidReviewsIoResponseException(
                message: 'Reviews.io API returned invalid data structure',
                previous: $e,
            );
        }
    }
}
```

**Note**: Tests will FAIL after this step (expected). Fixed in Phase 8.

---

### Phase 7: Create Factory and Update Provider

**File**: `app/Infrastructure/ReviewsIo/ReviewsIoClientFactory.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Application\Contracts\ReviewsIoClientInterface;
use RuntimeException;

final class ReviewsIoClientFactory
{
    public static function create(): ReviewsIoClientInterface
    {
        $apiKey = config('reviewsio.api_key');
        $storeId = config('reviewsio.store_id');

        if (!is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('REVIEWSIO_API_KEY not configured');
        }

        if (!is_string($storeId) || $storeId === '') {
            throw new RuntimeException('REVIEWSIO_STORE not configured');
        }

        $config = new ReviewsIoConfig(
            apiKey: $apiKey,
            storeId: $storeId,
            timeout: is_int($t = config('reviewsio.timeout')) ? $t : 30,
            retryTimes: is_int($r = config('reviewsio.retry_times')) ? $r : 3,
            retryDelay: is_int($d = config('reviewsio.retry_delay')) ? $d : 100,
        );

        return new ReviewsIoClient(new ReviewsIoHttpTransport($config));
    }
}
```

**Updated `ReviewsIoServiceProvider.php`**:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ReviewsIoClientInterface;
use App\Infrastructure\ReviewsIo\ReviewsIoClient;
use App\Infrastructure\ReviewsIo\ReviewsIoClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

final class ReviewsIoServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            ReviewsIoClientInterface::class,
            static fn() => ReviewsIoClientFactory::create(),
        );

        // Backward compatibility: concrete class resolves through interface
        $this->app->alias(ReviewsIoClientInterface::class, ReviewsIoClient::class);
    }

    /** @return list<class-string> */
    #[Override]
    public function provides(): array
    {
        return [ReviewsIoClientInterface::class, ReviewsIoClient::class];
    }
}
```

**Checkpoint**:
```bash
./vendor/bin/sail artisan verify:api reviewsio  # Should work now!
```

---

### Phase 8: Update Tests

#### Update `ReviewsIoClientTest.php` Setup

**Before**:
```php
$this->client = new ReviewsIoClient(
    apiKey: self::TEST_API_KEY,
    storeId: self::TEST_STORE_ID,
    timeout: 30,
    retryTimes: 3,
    retryDelay: 100,
);
```

**After**:
```php
$config = new ReviewsIoConfig(
    apiKey: self::TEST_API_KEY,
    storeId: self::TEST_STORE_ID,
    timeout: 30,
    retryTimes: 3,
    retryDelay: 100,
);
$transport = new ReviewsIoHttpTransport($config);
$this->client = new ReviewsIoClient($transport);
```

#### Move Constructor Validation Tests

Create `tests/Unit/Infrastructure/ReviewsIo/ReviewsIoConfigTest.php` with these tests moved from client test:
- `it_throws_exception_for_empty_api_key`
- `it_throws_exception_for_empty_store_id`
- All timeout boundary tests (4)
- All retry_times boundary tests (4)
- All retry_delay boundary tests (4)

#### Update Command to Use Interface

Change `app(ReviewsIoClient::class)` to `app(ReviewsIoClientInterface::class)` in the verification command.

**Final Checkpoint**:
```bash
./vendor/bin/sail artisan verify:api all
./vendor/bin/sail artisan test
./vendor/bin/sail composer lint
```

---

## Critical Files Reference

| File | Purpose |
|------|---------|
| `app/Infrastructure/ReviewsIo/ReviewsIoClient.php` | Core refactoring target |
| `app/Infrastructure/Mixpanel/MixpanelClientFactory.php` | Pattern template for factory |
| `app/Application/Contracts/MixpanelClientInterface.php` | Pattern template for interface |
| `app/Providers/GoogleAdsServiceProvider.php` | Pattern template for provider |
| `tests/Feature/Infrastructure/Api/ReviewsIoClientTest.php` | 41 tests to update |
| `app/Presentation/Console/Commands/SyncAdSpendCommand.php` | Pattern template for command |

---

## Verification Checkpoints Summary

| Phase | Command | Expected Result |
|-------|---------|-----------------|
| 1 | `verify:api reviewsio` | SUCCESS (current impl works) |
| 2 | `verify:api reviewsio` + `test --filter=ReviewsIoClientTest` | All pass |
| 3-5 | Same as Phase 2 | All pass (additions only) |
| 6 | Tests FAIL | Expected (constructor changed) |
| 7 | `verify:api reviewsio` | SUCCESS (factory wired) |
| 8 | `test` + `lint` | All pass |

---

## Files Changed Summary

**New (7)**:
- `app/Application/Contracts/ReviewsIoClientInterface.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoConfig.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoHttpTransport.php`
- `app/Infrastructure/ReviewsIo/ReviewsIoClientFactory.php`
- `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php`
- `tests/Feature/Presentation/Console/Commands/VerifyApiConnectivityCommandTest.php`
- `tests/Unit/Infrastructure/ReviewsIo/ReviewsIoConfigTest.php`

**Modified (3)**:
- `app/Infrastructure/ReviewsIo/ReviewsIoClient.php`
- `app/Providers/ReviewsIoServiceProvider.php`
- `tests/Feature/Infrastructure/Api/ReviewsIoClientTest.php`
