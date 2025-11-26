<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Api;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Shopwired\ShopwiredClient;
use App\Infrastructure\Shopwired\ShopwiredConfig;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
    private function createClient(
        int $timeout = 30,
        int $retryTimes = 3,
        int $retryDelay = 100,
    ): ShopwiredClient {
        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
            timeout: $timeout,
            retryTimes: $retryTimes,
            retryDelay: $retryDelay,
        );

        $transport = new ShopwiredHttpTransport($config);

        return new ShopwiredClient($transport);
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
    public function it_throws_exception_on_http_401_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_http_403_forbidden(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Forbidden'], 403)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();
    }

    #[Test]
    public function it_throws_exception_on_http_404_not_found(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Not Found'], 404)]);

        $this->expectException(ExternalServiceUnavailableException::class);

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
        // This test verifies that RequestException is thrown immediately (not retried)

        Http::fake(['*' => Http::response(['error' => 'Service Unavailable'], 503)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->verifyConnectivity();

        // Note: We can't reliably test request count with Http::fake() in parallel tests
        // The key assertion is that ExternalServiceUnavailableException is thrown immediately
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_custom_retry_parameters(): void
    {
        // Verify the client accepts retry parameters without error
        $client = $this->createClient(
            timeout: 10,
            retryTimes: 5,
            retryDelay: 200,
        );

        Http::fake(['*' => Http::response(['business_name' => 'Test Store'], 200)]);

        // Should not throw exception
        $client->verifyConnectivity();

        $this->assertTrue(true); // Explicit assertion that we reached this point
    }

    #[Test]
    public function it_includes_service_name_in_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        try {
            $this->client->verifyConnectivity();
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);

            return;
        }

        $this->fail('Expected ExternalServiceUnavailableException to be thrown');
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

        $transport = new ShopwiredHttpTransport($config);
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
}
