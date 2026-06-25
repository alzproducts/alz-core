<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\AdSpend\Mixpanel;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Mixpanel\MixpanelConfig;
use App\Infrastructure\Mixpanel\MixpanelHttpTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MixpanelHttpTransport Feature Tests.
 *
 * Tests the HTTP transport layer for the Mixpanel API, covering:
 * - Successful request execution for various HTTP methods (GET, POST, PUT)
 * - Correct application of Basic Auth and Content-Type headers
 * - Exception translation for 4xx/5xx HTTP errors (including rate limits)
 *   and connection failures
 * - Logging behavior for different error types
 * - Retry logic configuration and its conditional application
 */
#[CoversClass(MixpanelHttpTransport::class)]
final class MixpanelHttpTransportTest extends TestCase
{
    private const string TEST_USERNAME = 'test-user';
    private const string TEST_PASSWORD = 'test-password';
    private const string TEST_PROJECT_ID = 'test-project';
    /** @var array<string, string> */
    private const array TEST_LOOKUP_TABLE_IDS = ['utm_campaigns' => 'test-lookup'];
    private const string TEST_DATA_API_BASE_URL = 'https://test.api-eu.mixpanel.com';
    private const string TEST_EXPORT_API_BASE_URL = 'https://test.data-eu.mixpanel.com';
    private const string TEST_ANALYTICS_SALT = 'test-analytics-salt';
    private const int TEST_TIMEOUT_SECONDS = 5;
    private const int TEST_RETRY_TIMES = 1;
    private const int TEST_RETRY_DELAY_MS = 10;

    private MixpanelConfig $config;
    private MixpanelHttpTransport $transport;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new MixpanelConfig(
            dataApiBaseUrl: self::TEST_DATA_API_BASE_URL,
            exportApiBaseUrl: self::TEST_EXPORT_API_BASE_URL,
            serviceAccountUsername: self::TEST_USERNAME,
            serviceAccountPassword: self::TEST_PASSWORD,
            projectId: self::TEST_PROJECT_ID,
            analyticsSalt: self::TEST_ANALYTICS_SALT,
            lookupTableIds: self::TEST_LOOKUP_TABLE_IDS,
            timeout: self::TEST_TIMEOUT_SECONDS,
            retryTimes: self::TEST_RETRY_TIMES,
            retryDelay: self::TEST_RETRY_DELAY_MS,
        );

        $this->transport = new MixpanelHttpTransport($this->config, \app(TransientLogThrottle::class));
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_sends_get_request_successfully(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $url = self::TEST_DATA_API_BASE_URL . '/api/app/me';
        $response = $this->transport->request('GET', $url);

        Http::assertSent(static fn(Request $request): bool => $request->method() === 'GET' && $request->url() === $url);

        $this->assertTrue($response->successful());
        $this->assertSame(['status' => 'success'], $response->json());
    }

    #[Test]
    public function it_sends_post_request_with_json_body_successfully(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $url = self::TEST_DATA_API_BASE_URL . '/import';
        $body = \json_encode([['event' => 'Test Event']], JSON_THROW_ON_ERROR);
        $response = $this->transport->request('POST', $url, $body, 'application/json');

        Http::assertSent(static fn(Request $request): bool => $request->method() === 'POST'
                && $request->url() === $url
                && $request->body() === $body
                && $request->header('Content-Type')[0] === 'application/json');

        $this->assertTrue($response->successful());
    }

    #[Test]
    public function it_sends_put_request_with_csv_body_successfully(): void
    {
        Http::fake(['*' => Http::response(['status' => 'updated'], 200)]);

        $url = self::TEST_DATA_API_BASE_URL . '/lookup_tables/project/table';
        $body = "utm_campaign,campaign_name\ncmp1,Campaign 1";
        $response = $this->transport->request('PUT', $url, $body, 'text/csv');

        Http::assertSent(static fn(Request $request): bool => $request->method() === 'PUT'
                && $request->url() === $url
                && $request->body() === $body
                && $request->header('Content-Type')[0] === 'text/csv');

        $this->assertTrue($response->successful());
    }

    /*
    |--------------------------------------------------------------------------
    | Header Verification Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_sends_basic_auth_header(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $url = self::TEST_DATA_API_BASE_URL . '/events';
        $this->transport->request('GET', $url);

        Http::assertSent(function (Request $request): bool {
            $expectedAuthHeader = 'Basic ' . \base64_encode(self::TEST_USERNAME . ':' . self::TEST_PASSWORD);
            $this->assertSame($expectedAuthHeader, $request->header('Authorization')[0]);

            return true;
        });
    }

    #[Test]
    public function it_sets_content_type_header_when_body_is_provided(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $url = self::TEST_DATA_API_BASE_URL . '/events';
        $this->transport->request('POST', $url, 'some-content', 'text/plain');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('text/plain', $request->header('Content-Type')[0]);

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Limit (429) Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_on_rate_limit_with_retry_after(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);

        $url = self::TEST_DATA_API_BASE_URL . '/import';

        try {
            $this->transport->request('POST', $url, '{}', 'application/json');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Mixpanel', $e->serviceName);
            $this->assertSame(120, $e->retryAfter);
        }
    }

    #[Test]
    public function it_returns_null_retry_after_when_header_missing(): void
    {
        Http::fake(['*' => Http::response([], 429)]);

        $url = self::TEST_DATA_API_BASE_URL . '/import';

        try {
            $this->transport->request('POST', $url, '{}', 'application/json');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Mixpanel', $e->serviceName);
            $this->assertNull($e->retryAfter);
        }
    }

    #[Test]
    public function it_returns_null_retry_after_for_zero_value(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '0'])]);

        $url = self::TEST_DATA_API_BASE_URL . '/import';

        try {
            $this->transport->request('POST', $url, '{}', 'application/json');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    #[Test]
    public function it_returns_null_retry_after_for_negative_value(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '-1'])]);

        $url = self::TEST_DATA_API_BASE_URL . '/import';

        try {
            $this->transport->request('POST', $url, '{}', 'application/json');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    #[Test]
    public function it_logs_warning_for_rate_limit(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]);
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message): bool => \str_contains($message, 'rate limited'));

        $url = self::TEST_DATA_API_BASE_URL . '/import';

        try {
            $this->transport->request('POST', $url, '{}', 'application/json');
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Error Translation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_on_400_bad_request(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid parameters'], 400)]);

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('API request validation failed');

        $this->transport->request('POST', self::TEST_DATA_API_BASE_URL . '/import', '{}', 'application/json');
    }

    #[Test]
    public function it_throws_exception_on_401_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Authentication failed');

        $this->transport->request('GET', self::TEST_DATA_API_BASE_URL . '/api/app/me');
    }

    #[Test]
    public function it_throws_exception_on_403_forbidden(): void
    {
        Http::fake(['*' => Http::response(['error' => 'forbidden'], 403)]);

        $this->expectException(AuthenticationExpiredException::class);
        $this->expectExceptionMessage('Authentication failed');

        $this->transport->request('GET', self::TEST_DATA_API_BASE_URL . '/api/app/me');
    }

    #[Test]
    public function it_throws_exception_on_404_not_found(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

        $url = self::TEST_DATA_API_BASE_URL . '/lookup_tables/missing-table';

        try {
            $this->transport->request('GET', $url);
            $this->fail('Expected InvalidApiRequestException');
        } catch (InvalidApiRequestException $e) {
            $this->assertSame('Mixpanel', $e->serviceName);
            $this->assertStringContainsString($url, $e->detail);
        }
    }

    #[Test]
    public function it_logs_error_for_404_not_found(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);
        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'endpoint not found')
                    && $context['url'] === self::TEST_DATA_API_BASE_URL . '/lookup_tables/missing-table');

        try {
            $this->transport->request('GET', self::TEST_DATA_API_BASE_URL . '/lookup_tables/missing-table');
        } catch (InvalidApiRequestException) {
            // Expected
        }
    }

    #[Test]
    public function it_throws_exception_on_500_server_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'server error'], 500)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport->request('POST', self::TEST_DATA_API_BASE_URL . '/import', '{}', 'application/json');
    }

    #[Test]
    public function it_preserves_original_request_exception(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        try {
            $this->transport->request('POST', self::TEST_DATA_API_BASE_URL . '/import', '{}', 'application/json');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNotNull($e->getPrevious());
            $this->assertInstanceOf(RequestException::class, $e->getPrevious());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Connection Exception Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_on_connection_failure(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection refused');
        });

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport->request('GET', self::TEST_DATA_API_BASE_URL . '/api/app/me');
    }

    #[Test]
    public function it_preserves_original_connection_exception(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection refused');
        });

        try {
            $this->transport->request('GET', self::TEST_DATA_API_BASE_URL . '/api/app/me');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNotNull($e->getPrevious());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Retry Behavior Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_does_not_retry_when_retry_is_false(): void
    {
        $requestCount = 0;
        Http::fake(static function () use (&$requestCount) {
            $requestCount++;
            if ($requestCount === 1) {
                throw new ConnectionException('Temporary failure');
            }

            return Http::response([], 200);
        });

        try {
            $this->transport->request('GET', self::TEST_DATA_API_BASE_URL . '/api/app/me', retry: false);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException) {
            // Expected - should fail on first attempt without retry
            $this->assertSame(1, $requestCount);
        }
    }

    #[Test]
    public function it_sends_request_without_body_when_body_is_null(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $url = self::TEST_DATA_API_BASE_URL . '/api/app/me';
        $this->transport->request('GET', $url, body: null, contentType: null);

        Http::assertSent(static function (Request $request): bool {
            // When body is null, the request body should be empty
            return $request->body() === '';
        });
    }

    #[Test]
    public function it_requires_both_body_and_content_type_to_set_body(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $url = self::TEST_DATA_API_BASE_URL . '/events';

        // When only body is provided but not contentType, body should not be set
        $this->transport->request('POST', $url, body: 'some-content', contentType: null);

        Http::assertSent(static function (Request $request): bool {
            // Body should be empty because contentType was null
            return $request->body() === '';
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Unexpected Exception Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_unexpected_exceptions_from_http_internals(): void
    {
        $unexpectedException = new Exception('Unexpected Guzzle internal error');

        Http::fake(static function () use ($unexpectedException): never {
            throw $unexpectedException;
        });

        try {
            $this->transport->request('GET', self::TEST_DATA_API_BASE_URL . '/api/app/me');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Mixpanel', $e->serviceName);
            $this->assertSame($unexpectedException, $e->getPrevious());
        }
    }

}
