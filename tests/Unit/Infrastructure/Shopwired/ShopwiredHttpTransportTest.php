<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Shopwired\RetryStrategy;
use App\Infrastructure\Shopwired\ShopwiredConfig;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ShopwiredHttpTransport Unit Tests.
 *
 * Tests the HTTP transport layer for Shopwired API, covering:
 * - Exception translation for all HTTP status codes
 * - RetryStrategy integration (Background vs Urgent)
 * - Connection failure handling
 * - getResource() context enrichment
 */
#[CoversClass(ShopwiredHttpTransport::class)]
final class ShopwiredHttpTransportTest extends TestCase
{
    private const string TEST_API_KEY = 'test-api-key';
    private const string TEST_API_SECRET = 'test-api-secret';

    private ShopwiredHttpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new ShopwiredConfig(
            apiKey: self::TEST_API_KEY,
            apiSecret: self::TEST_API_SECRET,
        );

        $this->transport = new ShopwiredHttpTransport($config);
    }

    /*
    |--------------------------------------------------------------------------
    | GET Request Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_performs_successful_get_request(): void
    {
        Http::fake([
            '*' => Http::response(['data' => 'test'], 200),
        ]);

        $response = $this->transport->get('orders');

        $this->assertSame(200, $response->status());
        $this->assertSame(['data' => 'test'], $response->json());
    }

    #[Test]
    public function it_includes_query_parameters_in_get_request(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->transport->get('orders', ['status' => 'pending', 'limit' => 10]);

        Http::assertSent(static fn(Request $request): bool => \str_contains($request->url(), 'status=pending')
                && \str_contains($request->url(), 'limit=10'));
    }

    /*
    |--------------------------------------------------------------------------
    | POST Request Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_performs_successful_post_request(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        $response = $this->transport->post('orders/123/status', ['status' => 'shipped']);

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('success'));
    }

    #[Test]
    public function it_sends_post_data_as_json(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->transport->post('orders/123/status', ['status' => 'shipped']);

        Http::assertSent(static fn(Request $request): bool => $request->method() === 'POST'
                && $request->data() === ['status' => 'shipped']);
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Translation Tests (HTTP Status Codes)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_translates_400_to_invalid_api_request_exception(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Invalid order ID format'], 400),
        ]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid order ID format');

        $this->transport->get('orders/invalid');
    }

    #[Test]
    public function it_translates_400_with_fallback_message_when_no_message_in_response(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'bad'], 400),
        ]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid request parameters');

        $this->transport->get('orders');
    }

    #[Test]
    public function it_translates_401_to_authentication_expired_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        try {
            $this->transport->get('orders');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
            $this->assertStringContainsString('Invalid credentials', $e->getMessage());
        }
    }

    #[Test]
    public function it_translates_403_to_authentication_expired_exception_with_permissions_message(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        try {
            $this->transport->get('orders');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
            $this->assertStringContainsString('Insufficient permissions', $e->getMessage());
        }
    }

    #[Test]
    public function it_translates_404_to_resource_not_found_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        $this->expectException(ResourceNotFoundException::class);

        $this->transport->get('orders/999');
    }

    #[Test]
    public function it_translates_429_to_external_service_unavailable_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Rate limited'], 429, ['Retry-After' => '60']),
        ]);

        try {
            $this->transport->get('orders', retry: false);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
            $this->assertSame(60, $e->retryAfter);
        }
    }

    #[Test]
    #[DataProvider('serverErrorStatusCodes')]
    public function it_translates_5xx_to_external_service_unavailable_exception(int $statusCode): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Server error'], $statusCode),
        ]);

        try {
            $this->transport->get('orders', retry: false);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function serverErrorStatusCodes(): array
    {
        return [
            '500 Internal Server Error' => [500],
            '502 Bad Gateway' => [502],
            '503 Service Unavailable' => [503],
            '504 Gateway Timeout' => [504],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Connection Exception Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_translates_connection_exception_to_external_service_unavailable(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection refused');
        });

        try {
            $this->transport->get('orders', retry: false);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | getResource() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_enriches_404_with_resource_context_in_get_resource(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        try {
            $this->transport->getResource('Order', 12345, 'orders');
            $this->fail('Expected ResourceNotFoundException');
        } catch (ResourceNotFoundException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
            $this->assertSame('Order', $e->resourceType);
            $this->assertSame(12345, $e->resourceId);
            $this->assertStringContainsString('Order', $e->getMessage());
            $this->assertStringContainsString('12345', $e->getMessage());
        }
    }

    #[Test]
    public function it_passes_through_other_exceptions_in_get_resource(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(AuthenticationExpiredException::class);

        $this->transport->getResource('Order', 12345, 'orders');
    }

    /*
    |--------------------------------------------------------------------------
    | RetryStrategy Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_disables_retry_when_retry_parameter_is_false(): void
    {
        $callCount = 0;

        Http::fake(static function () use (&$callCount) {
            $callCount++;

            return Http::response(['error' => 'Server error'], 500);
        });

        try {
            $this->transport->get('orders', retry: false);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }

        $this->assertSame(1, $callCount, 'Should not retry when retry is disabled');
    }

    #[Test]
    public function it_uses_urgent_strategy_with_fewer_retries(): void
    {
        $callCount = 0;

        Http::fake(static function () use (&$callCount) {
            $callCount++;

            return Http::response(['error' => 'Server error'], 500);
        });

        try {
            $this->transport->get('orders', retry: true, strategy: RetryStrategy::Urgent);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }

        // Urgent strategy: 2 attempts (initial + 1 retry)
        $this->assertSame(2, $callCount);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_sends_basic_auth_credentials(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->transport->get('orders');

        Http::assertSent(static function (Request $request): bool {
            $authHeader = $request->header('Authorization');
            $expectedAuth = 'Basic ' . \base64_encode(self::TEST_API_KEY . ':' . self::TEST_API_SECRET);

            return \is_array($authHeader) && $authHeader[0] === $expectedAuth;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | poolPost() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_empty_array_for_empty_pool_requests(): void
    {
        $responses = $this->transport->poolPost([]);

        $this->assertSame([], $responses);
    }

    #[Test]
    public function it_performs_successful_pool_post_requests(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);

        $requests = [
            'sku-1' => ['endpoint' => 'stock/1', 'data' => ['quantity' => 10]],
            'sku-2' => ['endpoint' => 'stock/2', 'data' => ['quantity' => 20]],
        ];

        $responses = $this->transport->poolPost($requests);

        $this->assertCount(2, $responses);
        $this->assertArrayHasKey('sku-1', $responses);
        $this->assertArrayHasKey('sku-2', $responses);
        $this->assertSame(200, $responses['sku-1']->status());
        $this->assertSame(200, $responses['sku-2']->status());
    }

    #[Test]
    public function it_translates_pool_connection_exception_to_external_service_unavailable(): void
    {
        // Pool uses Guzzle's ConnectException internally, which Laravel wraps as ConnectionException
        Http::fake(static function (): never {
            throw new ConnectException(
                'Connection refused',
                new GuzzleRequest('POST', 'https://api.shopwired.co.uk/stock/1'),
            );
        });

        $requests = [
            'sku-1' => ['endpoint' => 'stock/1', 'data' => ['quantity' => 10]],
        ];

        try {
            $this->transport->poolPost($requests);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
        }
    }

    #[Test]
    public function it_translates_pool_failed_response_to_domain_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $requests = [
            'sku-1' => ['endpoint' => 'stock/1', 'data' => ['quantity' => 10]],
        ];

        $this->expectException(AuthenticationExpiredException::class);

        $this->transport->poolPost($requests);
    }

    /*
    |--------------------------------------------------------------------------
    | Unexpected Exception Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_translates_unexpected_get_exception_to_external_service_unavailable(): void
    {
        Http::fake(static function (): never {
            throw new RuntimeException('Unexpected Guzzle error');
        });

        try {
            $this->transport->get('orders', retry: false);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
        }
    }

    #[Test]
    public function it_translates_unexpected_post_exception_to_external_service_unavailable(): void
    {
        Http::fake(static function (): never {
            throw new RuntimeException('Unexpected Guzzle error');
        });

        try {
            $this->transport->post('orders/123/status', ['status' => 'shipped'], retry: false);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST Exception Translation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_translates_post_connection_exception_to_external_service_unavailable(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection refused');
        });

        try {
            $this->transport->post('orders/123/status', ['status' => 'shipped'], retry: false);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Shopwired', $e->serviceName);
        }
    }

    #[Test]
    public function it_translates_post_400_to_invalid_api_request_exception(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Invalid status value'], 400),
        ]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid status value');

        $this->transport->post('orders/123/status', ['status' => 'invalid'], retry: false);
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Tests (Mutation Testing Coverage)
    |--------------------------------------------------------------------------
    | These tests verify that error handlers log with service name and context.
    | They kill ConcatOperandRemoval and ArrayItemRemoval mutations.
    */

    #[Test]
    public function it_logs_400_error_with_service_name_and_context(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Bad request'], 400),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'invalid request')),
                Mockery::on(
                    static fn(array $ctx): bool => isset($ctx['status']) && $ctx['status'] === 400
                        && isset($ctx['error'], $ctx['response']),
                ),
            );

        try {
            $this->transport->get('orders', retry: false);
        } catch (InvalidApiRequestException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_401_error_with_service_name_and_context(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'authentication')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['status']) && $ctx['status'] === 401
                        && isset($ctx['error'])),
            );

        try {
            $this->transport->get('orders', retry: false);
        } catch (AuthenticationExpiredException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_403_error_with_service_name_and_context(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'authentication')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['status']) && $ctx['status'] === 403
                        && isset($ctx['error'])),
            );

        try {
            $this->transport->get('orders', retry: false);
        } catch (AuthenticationExpiredException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_404_warning_with_service_name_and_context(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'not found')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['endpoint'])
                        && isset($ctx['error'])),
            );

        try {
            $this->transport->get('orders/999', retry: false);
        } catch (ResourceNotFoundException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_429_warning_with_service_name_and_retry_after(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Rate limited'], 429, ['Retry-After' => '120']),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'rate limited')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['retry_after']) && $ctx['retry_after'] === 120
                        && isset($ctx['error'])),
            );

        try {
            $this->transport->get('orders', retry: false);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_5xx_error_with_service_name_and_status(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Internal error'], 500),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'request failed')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['status']) && $ctx['status'] === 500
                        && isset($ctx['error'])),
            );

        try {
            $this->transport->get('orders', retry: false);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_connection_error_with_service_name_and_message(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection timed out');
        });

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'connection failed')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['error']) && \str_contains($ctx['error'], 'Connection timed out')),
            );

        try {
            $this->transport->get('orders', retry: false);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_unexpected_error_with_service_name_and_exception_class(): void
    {
        Http::fake(static function (): never {
            throw new RuntimeException('Something unexpected');
        });

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'unexpected')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['exception']) && $ctx['exception'] === RuntimeException::class
                        && isset($ctx['error'])),
            );

        try {
            $this->transport->get('orders', retry: false);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_pool_failure_with_service_name_and_key(): void
    {
        // Pool uses Guzzle's ConnectException internally
        Http::fake(static function (): never {
            throw new ConnectException(
                'Pool connection failed',
                new GuzzleRequest('POST', 'https://api.shopwired.co.uk/stock/1'),
            );
        });

        // Pool failure logs twice: once for pool error (with key), then handleConnectionException logs again
        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'pool')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['key']) && $ctx['key'] === 'sku-1'
                        && isset($ctx['error'])),
            );

        Log::shouldReceive('error')
            ->once()
            ->with(
                Mockery::on(static fn(string $msg): bool => \str_contains($msg, 'Shopwired') && \str_contains($msg, 'connection failed')),
                Mockery::on(static fn(array $ctx): bool => isset($ctx['error'])),
            );

        $requests = [
            'sku-1' => ['endpoint' => 'stock/1', 'data' => ['quantity' => 10]],
        ];

        try {
            $this->transport->poolPost($requests);
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }
}
