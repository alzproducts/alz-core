<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Http\Authenticator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(HelpScoutHttpTransport::class)]
final class HelpScoutHttpTransportTest extends TestCase
{
    private HelpScoutConfig $config;

    private ApiClient&MockInterface $mockSdkClient;

    private HelpScoutHttpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            timeoutSeconds: 10,
            retryAttempts: 1,
        );

        $mockAuthenticator = Mockery::mock(Authenticator::class);
        $mockAuthenticator->allows('getAuthHeader')->andReturn(['Authorization' => 'Bearer test-token']);

        $this->mockSdkClient = Mockery::mock(ApiClient::class);
        $this->mockSdkClient->allows('getAuthenticator')->andReturn($mockAuthenticator);

        $this->transport = new HelpScoutHttpTransport($this->config, $this->mockSdkClient);
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Request Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_returns_response_on_success(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response(['data' => 'test'], 200),
        ]);

        $response = $this->transport->get('/conversations');

        $this->assertSame(200, $response->status());
        $this->assertSame(['data' => 'test'], $response->json());
    }

    #[Test]
    public function get_sends_authorization_header(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 200),
        ]);

        $this->transport->get('/conversations');

        Http::assertSent(static fn(Request $request) => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    #[Test]
    public function get_includes_query_params(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 200),
        ]);

        $this->transport->get('/conversations', ['status' => 'active', 'page' => 2]);

        Http::assertSent(static fn(Request $request) => \str_contains($request->url(), 'status=active')
                && \str_contains($request->url(), 'page=2'));
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Translation Tests - 400 Bad Request
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_translates_400_to_invalid_api_request_exception(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response(
                ['message' => 'Invalid mailbox ID'],
                400,
            ),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('HelpScout API invalid request', Mockery::any());

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid mailbox ID');

        $this->transport->get('/conversations');
    }

    #[Test]
    public function get_uses_fallback_message_when_400_response_has_no_message(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response(['error' => 'something'], 400),
        ]);

        Log::shouldReceive('error')->once();

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('Invalid request parameters');

        $this->transport->get('/conversations');
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Translation Tests - 401/403 Authentication
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_translates_401_to_authentication_expired_exception(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 401),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('HelpScout API authentication failed', Mockery::hasKey('status'));

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->transport->get('/conversations');
    }

    #[Test]
    public function get_translates_403_to_authentication_expired_exception(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 403),
        ]);

        Log::shouldReceive('error')->once();

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Insufficient permissions');

        $this->transport->get('/conversations');
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Translation Tests - 429 Rate Limit
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_translates_429_to_external_service_unavailable_exception(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 429, ['Retry-After' => '60']),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('HelpScout API rate limited', Mockery::hasKey('retry_after'));

        try {
            $this->transport->get('/conversations');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('HelpScout', $e->serviceName);
            $this->assertSame(60, $e->retryAfter);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Translation Tests - 5xx Server Errors
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_translates_500_to_external_service_unavailable_exception(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 500),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('HelpScout API request failed', Mockery::hasKey('status'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport->get('/conversations');
    }

    #[Test]
    public function get_translates_503_to_external_service_unavailable_exception(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 503),
        ]);

        Log::shouldReceive('error')->once();

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport->get('/conversations');
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Translation Tests - Connection Errors
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_translates_connection_exception_to_external_service_unavailable(): void
    {
        Http::fake([
            'api.helpscout.net/*' => static fn() => throw new ConnectionException('Connection refused'),
        ]);

        Log::shouldReceive('error')
            ->once()
            ->with('HelpScout API connection failed', Mockery::any());

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport->get('/conversations');
    }

    /*
    |--------------------------------------------------------------------------
    | Request Configuration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_uses_correct_base_url(): void
    {
        Http::fake([
            'api.helpscout.net/*' => Http::response([], 200),
        ]);

        $this->transport->get('/mailboxes');

        Http::assertSent(static fn(Request $request) => \str_starts_with($request->url(), 'https://api.helpscout.net/v2/mailboxes'));
    }
}
