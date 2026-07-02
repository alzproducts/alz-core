<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Api;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\ShopwiredClient;
use App\Infrastructure\Shopwired\ShopwiredConfig;
use App\Infrastructure\Shopwired\ShopwiredErrorHandler;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ShopwiredClient Feature Tests.
 *
 * Tests the Shopwired API client implementation covering:
 * - Success paths (connectivity verification)
 * - HTTP Basic Auth configuration
 * - API errors (HTTP 4xx/5xx, network failures)
 * - Rate limiting with Retry-After header
 * - Retry behavior (disabled for verifyConnectivity)
 *
 * Note: Constructor validation tests are in ShopwiredConfigTest.
 */
#[CoversClass(ShopwiredClient::class)]
#[CoversClass(ShopwiredHttpTransport::class)]
final class ShopwiredClientTest extends TestCase
{
    private const string TEST_API_KEY = 'test-api-key';
    private const string TEST_API_SECRET = 'test-api-secret';

    private ShopwiredClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createClient();
    }

    /**
     * Create a ShopwiredClient with default test configuration.
     */
    private function createClient(int $timeout = 30): ShopwiredClient
    {
        return new ShopwiredClient($this->createTransport($timeout));
    }

    /**
     * Create a ShopwiredHttpTransport with default test configuration.
     */
    private function createTransport(int $timeout = 30): ShopwiredHttpTransport
    {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            timeout: $timeout,
        );

        return new ShopwiredHttpTransport($config, new ShopwiredErrorHandler(\app(TransientLogThrottle::class)));
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Connectivity Success Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_connectivity_successfully(): void
    {
        Http::fake([
            '*' => Http::response(['business_name' => 'Test Store'], 200),
        ]);

        // Should not throw any exception
        $this->client->verifyConnectivity();

        Http::assertSent(function (Request $request) {
            // Verify it uses the /business endpoint
            $this->assertStringContainsString('/business', $request->url());

            return true;
        });
    }

    #[Test]
    public function it_sends_http_basic_auth_credentials(): void
    {
        Http::fake([
            '*' => Http::response(['business_name' => 'Test Store'], 200),
        ]);

        $this->client->verifyConnectivity();

        Http::assertSent(function (Request $request) {
            // Verify Basic Auth header is present
            $authHeader = $request->header('Authorization');
            $this->assertNotEmpty($authHeader);
            $this->assertIsArray($authHeader);

            // Verify it's Basic auth with correct credentials
            $expectedAuth = 'Basic ' . \base64_encode(self::TEST_API_KEY . ':' . self::TEST_API_SECRET);
            $this->assertSame($expectedAuth, $authHeader[0]);

            return true;
        });
    }

    #[Test]
    public function it_uses_correct_base_url(): void
    {
        Http::fake([
            '*' => Http::response(['business_name' => 'Test Store'], 200),
        ]);

        $this->client->verifyConnectivity();

        Http::assertSent(function (Request $request) {
            $this->assertStringStartsWith('https://api.ecommerceapi.uk/v1', $request->url());

            return true;
        });
    }

    #[Test]
    public function it_uses_get_method_for_business_endpoint(): void
    {
        Http::fake([
            '*' => Http::response(['business_name' => 'Test Store'], 200),
        ]);

        $this->client->verifyConnectivity();

        Http::assertSent(function (Request $request) {
            $this->assertSame('GET', $request->method());

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | API Error Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_on_http_400_bad_request(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid parameters'], 400)]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('API request validation failed');

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_http_401_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Authentication failed');

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_http_403_forbidden(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Forbidden'], 403)]);

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Authentication failed');

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_http_404_not_found(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Not Found'], 404)]);

        $this->expectException(ResourceNotAvailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_http_500_server_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Internal Server Error'], 500)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_http_503_service_unavailable(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Service Unavailable'], 503)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    /*
    |--------------------------------------------------------------------------
    | Network Error Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_on_connection_failure(): void
    {
        Http::fake(static fn() => throw new ConnectionException('Could not resolve host'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_timeout(): void
    {
        Http::fake(static fn() => throw new ConnectionException('Connection timed out'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_handles_unexpected_exceptions(): void
    {
        // Test the catch-all handler for unexpected exceptions (Guzzle internals, etc.)
        Http::fake(static fn() => throw new LogicException('Unexpected internal error'));

        try {
            $this->client->verifyConnectivity();
            $this->fail('Expected ExternalServiceUnavailableException to be thrown');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
            $this->assertInstanceOf(LogicException::class, $e->getPrevious());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Limit (429) Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_external_service_unavailable_on_rate_limit(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_extracts_retry_after_from_rate_limit_response(): void
    {
        Http::fake([
            '*' => Http::response([], 429, ['Retry-After' => '120']),
        ]);

        try {
            $this->client->verifyConnectivity();
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame(120, $e->retryAfter);

            return;
        }

        $this->fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_returns_null_retry_after_when_header_missing_on_rate_limit(): void
    {
        Http::fake([
            '*' => Http::response([], 429),
        ]);

        try {
            $this->client->verifyConnectivity();
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNull($e->retryAfter);

            return;
        }

        $this->fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    /*
    |--------------------------------------------------------------------------
    | Retry Behavior Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_does_not_retry_on_verify_connectivity(): void
    {
        // verifyConnectivity uses retry: false for fail-fast behavior
        // Track request count to verify no retry occurs
        $requestCount = 0;
        Http::fake(static function () use (&$requestCount) {
            $requestCount++;
            if ($requestCount === 1) {
                throw new ConnectionException('Temporary network failure');
            }

            return Http::response(['business_name' => 'Test Store'], 200);
        });

        try {
            $this->client->verifyConnectivity();
            $this->fail('Expected ExternalServiceUnavailableException to be thrown');
        } catch (ExternalServiceUnavailableException) {
            // Assert exactly 1 request was made (no retry occurred)
            $this->assertSame(1, $requestCount);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_includes_service_name_in_auth_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        try {
            $this->client->verifyConnectivity();
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Shopwired', $e->serviceName);

            return;
        }

        $this->fail('Expected AuthenticationExpiredException to be thrown');
    }

    #[Test]
    public function it_includes_service_name_in_unavailable_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Server Error'], 500)]);

        try {
            $this->client->verifyConnectivity();
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);

            return;
        }

        $this->fail('Expected ExternalServiceUnavailableException to be thrown');
    }

    #[Test]
    public function it_includes_service_name_in_invalid_request_exception(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad request'], 400)]);

        try {
            $this->client->verifyConnectivity();
        } catch (InvalidApiRequestException $e) {
            $this->assertSame('Shopwired', $e->serviceName);

            return;
        }

        $this->fail('Expected InvalidApiRequestException to be thrown');
    }

    /*
    |--------------------------------------------------------------------------
    | URL Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('urlCombinations')]
    public function it_builds_url_correctly_with_various_slash_combinations(
        string $baseUrl,
        string $endpoint,
        string $expectedUrlPrefix,
    ): void {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            baseUrl: $baseUrl,
        );

        $transport = new ShopwiredHttpTransport($config, new ShopwiredErrorHandler(\app(TransientLogThrottle::class)));
        $client = new ShopwiredClient($transport);

        Http::fake(['*' => Http::response(['business_name' => 'Test Store'], 200)]);

        $client->verifyConnectivity();

        Http::assertSent(function (Request $request) use ($expectedUrlPrefix) {
            $this->assertStringStartsWith($expectedUrlPrefix, $request->url());

            return true;
        });
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function urlCombinations(): array
    {
        return [
            'no trailing slash on base' => ['https://api.test.com/v1', 'business', 'https://api.test.com/v1/business'],
            'trailing slash on base' => ['https://api.test.com/v1/', 'business', 'https://api.test.com/v1/business'],
            'leading slash on endpoint' => ['https://api.test.com/v1', '/business', 'https://api.test.com/v1/business'],
            'both slashes' => ['https://api.test.com/v1/', '/business', 'https://api.test.com/v1/business'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP 422 Handling Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_invalid_request_on_http_422(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unprocessable Entity'], 422)]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('API request validation failed');

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_logs_actual_status_code_for_422(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Validation failed'], 422)]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Shopwired API invalid request'
                    && $context['status'] === 422);

        try {
            $this->client->verifyConnectivity();
        } catch (InvalidApiRequestException) {
            // Expected
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pool Error Routing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function pool_routes_422_response_to_invalid_api_request_exception(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unprocessable'], 422)]);

        $transport = $this->createTransport();
        $result = $transport->poolPost([
            'batch-1' => ['endpoint' => 'orders/123/status', 'data' => ['status' => 'shipped']],
        ]);

        $this->assertInstanceOf(InvalidApiRequestException::class, $result->transportFailures[0]);
    }

    #[Test]
    public function pool_routes_500_response_to_external_service_unavailable_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Internal Server Error'], 500)]);

        $transport = $this->createTransport();
        $result = $transport->poolPost([
            'batch-1' => ['endpoint' => 'orders/123/status', 'data' => ['status' => 'shipped']],
        ]);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result->transportFailures[0]);
    }

    #[Test]
    public function pool_routes_connection_failure_to_external_service_unavailable_exception(): void
    {
        Http::fake(static fn() => throw new ConnectionException('Connection refused'));

        $transport = $this->createTransport();
        $result = $transport->poolPost([
            'batch-1' => ['endpoint' => 'orders/123/status', 'data' => ['status' => 'shipped']],
        ]);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result->transportFailures[0]);
    }
}
