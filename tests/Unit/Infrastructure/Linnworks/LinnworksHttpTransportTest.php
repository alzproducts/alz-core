<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;
use App\Infrastructure\Linnworks\LinnworksSession;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LinnworksHttpTransport Unit Tests.
 *
 * Tests the HTTP transport layer for Linnworks API, covering:
 * - Session-based authentication (Authorization header, NOT Bearer)
 * - Automatic 401 retry with re-authentication
 * - Exception translation for all HTTP status codes
 * - POST request format (form-encoded with 'request' JSON wrapper)
 * - Connection failure handling
 */
#[CoversClass(LinnworksHttpTransport::class)]
final class LinnworksHttpTransportTest extends TestCase
{
    private const string TEST_TOKEN = 'test-auth-token';
    private const string TEST_SERVER_URL = 'https://eu-ext.linnworks.net';

    private MockInterface&LinnworksSessionManager $sessionManager;
    private LinnworksHttpTransport $transport;
    private LinnworksSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new LinnworksConfig(
            applicationId: 'test-app-id',
            applicationSecret: 'test-app-secret',
            installationToken: 'test-install-token',
        );

        $this->session = new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->sessionManager = Mockery::mock(LinnworksSessionManager::class);
        $this->sessionManager->shouldReceive('getSession')
            ->andReturn($this->session)
            ->byDefault();

        $this->transport = new LinnworksHttpTransport($config, $this->sessionManager);
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

        $response = $this->transport->get('/api/Inventory/GetStockItem');

        $this->assertSame(200, $response->status());
        $this->assertSame(['data' => 'test'], $response->json());
    }

    #[Test]
    public function it_includes_query_parameters_in_get_request(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->transport->get('/api/Inventory/GetStockItem', ['stockItemId' => 'abc-123']);

        Http::assertSent(static fn(Request $request): bool => \str_contains($request->url(), 'stockItemId=abc-123'));
    }

    #[Test]
    public function it_uses_session_server_url_as_base_url(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->transport->get('/api/Inventory/GetStockItem');

        Http::assertSent(static fn(Request $request): bool => \str_starts_with($request->url(), self::TEST_SERVER_URL));
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

        $response = $this->transport->post('/api/Inventory/UpdateStock', ['StockItemId' => 'abc-123']);

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('success'));
    }

    #[Test]
    public function it_sends_post_data_as_form_encoded_with_request_wrapper(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $data = ['StockItemId' => 'abc-123', 'Quantity' => 10];
        $this->transport->post('/api/Inventory/UpdateStock', $data);

        Http::assertSent(static function (Request $request) use ($data): bool {
            // Linnworks expects form-encoded POST with 'request' containing JSON
            $formData = $request->data();

            return $request->method() === 'POST'
                && isset($formData['request'])
                && $formData['request'] === \json_encode($data, \JSON_THROW_ON_ERROR);
        });
    }

    #[Test]
    public function it_sends_empty_form_data_for_empty_post_body(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->transport->post('/api/Inventory/SomeEndpoint');

        Http::assertSent(static fn(Request $request): bool => $request->data() === []);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication Header Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_sends_raw_token_in_authorization_header(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->transport->get('/api/Inventory/GetStockItem');

        Http::assertSent(static function (Request $request): bool {
            $authHeader = $request->header('Authorization');

            // Linnworks uses raw token, NOT Bearer token
            return \is_array($authHeader) && $authHeader[0] === self::TEST_TOKEN;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | 401 Retry Tests (Automatic Re-authentication)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_retries_once_on_401_with_fresh_session(): void
    {
        $callCount = 0;

        Http::fake(static function () use (&$callCount) {
            $callCount++;

            // First call returns 401, second call succeeds
            if ($callCount === 1) {
                return Http::response(['error' => 'Unauthorized'], 401);
            }

            return Http::response(['success' => true], 200);
        });

        // Session manager should be called twice (initial + after invalidation)
        $this->sessionManager->shouldReceive('invalidate')->once();
        $this->sessionManager->shouldReceive('getSession')
            ->twice()
            ->andReturn($this->session);

        $response = $this->transport->get('/api/Inventory/GetStockItem');

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function it_throws_authentication_exception_when_retry_also_fails_with_401(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->sessionManager->shouldReceive('invalidate')->once();
        $this->sessionManager->shouldReceive('getSession')
            ->twice()
            ->andReturn($this->session);

        try {
            $this->transport->get('/api/Inventory/GetStockItem');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertStringContainsString('Invalid credentials', $e->getMessage());
        }
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
            '*' => Http::response(['Message' => 'Invalid stock item ID'], 400),
        ]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid stock item ID');

        $this->transport->get('/api/Inventory/GetStockItem');
    }

    #[Test]
    public function it_translates_400_with_fallback_message_when_no_message_in_response(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid request parameters');

        $this->transport->get('/api/Inventory/GetStockItem');
    }

    #[Test]
    public function it_translates_403_to_authentication_expired_exception_with_permissions_message(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Forbidden'], 403),
        ]);

        try {
            $this->transport->get('/api/Inventory/GetStockItem');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
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

        $this->transport->get('/api/Inventory/GetStockItem', ['stockItemId' => 'nonexistent']);
    }

    #[Test]
    public function it_translates_429_to_external_service_unavailable_exception(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Rate limited'], 429, ['Retry-After' => '120']),
        ]);

        try {
            $this->transport->get('/api/Inventory/GetStockItem');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertSame(120, $e->retryAfter);
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
            $this->transport->get('/api/Inventory/GetStockItem');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
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
            $this->transport->get('/api/Inventory/GetStockItem');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST Exception Translation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_translates_post_400_to_invalid_api_request_exception(): void
    {
        Http::fake([
            '*' => Http::response(['Message' => 'request is empty'], 400),
        ]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('request is empty');

        $this->transport->post('/api/Inventory/UpdateStock', []);
    }

    #[Test]
    public function it_retries_post_on_401(): void
    {
        $callCount = 0;

        Http::fake(static function () use (&$callCount) {
            $callCount++;

            if ($callCount === 1) {
                return Http::response(['error' => 'Unauthorized'], 401);
            }

            return Http::response(['success' => true], 200);
        });

        $this->sessionManager->shouldReceive('invalidate')->once();
        $this->sessionManager->shouldReceive('getSession')
            ->twice()
            ->andReturn($this->session);

        $response = $this->transport->post('/api/Inventory/UpdateStock', ['data' => 'test']);

        $this->assertSame(200, $response->status());
        $this->assertSame(2, $callCount);
    }
}
