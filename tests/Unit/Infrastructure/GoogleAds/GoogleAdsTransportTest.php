<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\Infrastructure\GoogleAds\GoogleAdsTransport;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V22\Services\Client\GoogleAdsServiceClient;
use Google\ApiCore\ApiException;
use Google\ApiCore\PagedListResponse;
use Google\Rpc\Code;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GoogleAdsTransport Unit Tests.
 *
 * Tests the SDK transport layer for the Google Ads API, covering:
 * - Successful query execution
 * - Exception translation for rate limits (RESOURCE_EXHAUSTED)
 * - Exception translation for other API errors
 * - Logging behavior for different error types
 */
#[CoversClass(GoogleAdsTransport::class)]
final class GoogleAdsTransportTest extends TestCase
{
    private GoogleAdsConfig $config;
    private SdkGoogleAdsClient&MockInterface $mockSdkClient;
    private GoogleAdsServiceClient&MockInterface $mockServiceClient;
    private GoogleAdsTransport $transport;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new GoogleAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            customerId: '1234567890',
        );

        $this->mockServiceClient = Mockery::mock(GoogleAdsServiceClient::class);
        $this->mockSdkClient = Mockery::mock(SdkGoogleAdsClient::class);
        $this->mockSdkClient
            ->shouldReceive('getGoogleAdsServiceClient')
            ->andReturn($this->mockServiceClient);

        $this->transport = new GoogleAdsTransport($this->mockSdkClient, $this->config);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_executes_search_query_successfully(): void
    {
        $mockResponse = Mockery::mock(PagedListResponse::class);

        // Note: Page size is fixed by the API at 10000, no explicit setting needed
        $this->mockServiceClient
            ->shouldReceive('search')
            ->once()
            ->withArgs(static fn($request) => $request->getCustomerId() === '1234567890'
                    && \str_contains($request->getQuery(), 'SELECT campaign.id'))
            ->andReturn($mockResponse);

        $query = 'SELECT campaign.id FROM campaign';
        $result = $this->transport->search($query);

        $this->assertSame($mockResponse, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Rate Limit (RESOURCE_EXHAUSTED) Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_on_rate_limit_with_retry_after(): void
    {
        $apiException = $this->createApiExceptionWithMetadata(Code::RESOURCE_EXHAUSTED, ['retry-after' => 120]);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Google Ads', $e->serviceName);
            $this->assertSame(120, $e->retryAfter);
            $this->assertSame($apiException, $e->getPrevious());
        }
    }

    #[Test]
    public function it_returns_null_retry_after_when_metadata_missing(): void
    {
        $apiException = $this->createApiExceptionWithMetadata(Code::RESOURCE_EXHAUSTED, []);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Google Ads', $e->serviceName);
            $this->assertNull($e->retryAfter);
        }
    }

    #[Test]
    public function it_logs_warning_for_rate_limit_with_context(): void
    {
        $apiException = $this->createApiExceptionWithMetadata(Code::RESOURCE_EXHAUSTED, ['retry-after' => 60]);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                // Validate message contains both service name and action
                $hasServiceName = \str_contains($message, 'Google Ads');
                $hasRateLimited = \str_contains($message, 'rate limited');

                // Validate context has expected keys with values
                $hasRetryAfter = \array_key_exists('retry_after', $context) && $context['retry_after'] === 60;
                $hasError = \array_key_exists('error', $context) && \is_string($context['error']);

                return $hasServiceName && $hasRateLimited && $hasRetryAfter && $hasError;
            });

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_parses_string_retry_after_value(): void
    {
        $apiException = $this->createApiExceptionWithMetadata(Code::RESOURCE_EXHAUSTED, ['retry-after' => '180']);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame(180, $e->retryAfter);
        }
    }

    #[Test]
    public function it_returns_null_retry_after_for_invalid_type(): void
    {
        // Array is not a valid retry-after type (should be int or string)
        $apiException = $this->createApiExceptionWithMetadata(Code::RESOURCE_EXHAUSTED, ['retry-after' => ['invalid']]);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Other API Error Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_exception_on_api_error(): void
    {
        $apiException = $this->createApiException(Code::INTERNAL);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport->search('SELECT campaign.id FROM campaign');
    }

    #[Test]
    public function it_logs_error_for_non_rate_limit_api_exceptions_with_context(): void
    {
        $apiException = $this->createApiException(Code::INTERNAL);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                // Validate message contains both service name and error type
                $hasServiceName = \str_contains($message, 'Google Ads');
                $hasApiError = \str_contains($message, 'API error');

                // Validate context has expected keys with values
                $hasCode = \array_key_exists('code', $context) && $context['code'] === Code::INTERNAL;
                $hasError = \array_key_exists('error', $context) && \is_string($context['error']);

                return $hasServiceName && $hasApiError && $hasCode && $hasError;
            });

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_preserves_original_api_exception(): void
    {
        $apiException = $this->createApiException(Code::INTERNAL);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame($apiException, $e->getPrevious());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication Error Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_authentication_exception_on_permission_denied(): void
    {
        $apiException = $this->createApiException(Code::PERMISSION_DENIED);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        $this->expectException(AuthenticationExpiredException::class);

        $this->transport->search('SELECT campaign.id FROM campaign');
    }

    #[Test]
    public function it_logs_error_for_authentication_failure_with_context(): void
    {
        $apiException = $this->createApiException(Code::PERMISSION_DENIED);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                $hasServiceName = \str_contains($message, 'Google Ads');
                $hasAuthFailed = \str_contains($message, 'authentication failed');
                $hasCode = \array_key_exists('code', $context) && $context['code'] === Code::PERMISSION_DENIED;
                $hasError = \array_key_exists('error', $context) && \is_string($context['error']);

                return $hasServiceName && $hasAuthFailed && $hasCode && $hasError;
            });

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
        } catch (AuthenticationExpiredException) {
            // Expected
        }
    }

    #[Test]
    public function it_throws_authentication_exception_on_unauthenticated(): void
    {
        $apiException = $this->createApiException(Code::UNAUTHENTICATED);

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        $this->expectException(AuthenticationExpiredException::class);

        $this->transport->search('SELECT campaign.id FROM campaign');
    }

    #[Test]
    public function it_extracts_google_ads_error_code_from_json_message(): void
    {
        $jsonError = \json_encode([
            'message' => 'The caller does not have permission',
            'code' => 7,
            'details' => [[
                'errors' => [[
                    'errorCode' => ['authorizationError' => 'DEVELOPER_TOKEN_NOT_APPROVED'],
                    'message' => 'The developer token is only approved for use with test accounts.',
                ]],
            ]],
        ]);

        $apiException = new ApiException($jsonError, Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertStringContainsString('DEVELOPER_TOKEN_NOT_APPROVED', $e->getMessage());
        }
    }

    #[Test]
    public function it_returns_original_message_when_not_json(): void
    {
        $plainTextError = 'Simple text error message';
        $apiException = new ApiException($plainTextError, Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('error')->once();

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            // AuthenticationExpiredException format: "{serviceName}: {message}"
            $this->assertSame('Google Ads: Simple text error message', $e->getMessage());
        }
    }

    #[Test]
    public function it_uses_top_level_message_when_error_details_missing(): void
    {
        $jsonError = \json_encode([
            'message' => 'Top level error message',
            'code' => 7,
            // No 'details' key - simulates partial API response
        ]);

        $apiException = new ApiException($jsonError, Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('error')->once();

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            // AuthenticationExpiredException format: "{serviceName}: {message}"
            $this->assertSame('Google Ads: Top level error message', $e->getMessage());
        }
    }

    #[Test]
    public function it_falls_back_to_original_when_json_has_no_useful_fields(): void
    {
        // JSON that decodes but has no message, details, or top-level message
        $jsonError = \json_encode(['code' => 7]);

        $apiException = new ApiException($jsonError, Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('error')->once();

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            // Falls back to original message; AuthenticationExpiredException format: "{serviceName}: {message}"
            $this->assertSame('Google Ads: ' . $jsonError, $e->getMessage());
        }
    }

    #[Test]
    public function it_handles_empty_error_code_array(): void
    {
        $jsonError = \json_encode([
            'message' => 'Permission denied',
            'code' => 7,
            'details' => [[
                'errors' => [[
                    'errorCode' => [], // Empty errorCode array
                    'message' => 'Detailed error message',
                ]],
            ]],
        ]);

        $apiException = new ApiException($jsonError, Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('error')->once();

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            // Should use UNKNOWN when errorCode is empty; AuthenticationExpiredException format: "{serviceName}: {message}"
            $this->assertSame('Google Ads: UNKNOWN - Detailed error message', $e->getMessage());
        }
    }

    #[Test]
    public function it_handles_non_string_error_message_in_details(): void
    {
        $jsonError = \json_encode([
            'message' => 'Top level fallback',
            'code' => 7,
            'details' => [[
                'errors' => [[
                    'errorCode' => ['authError' => 'SOME_ERROR'],
                    'message' => 12345, // Non-string message
                ]],
            ]],
        ]);

        $apiException = new ApiException($jsonError, Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->mockServiceClient
            ->shouldReceive('search')
            ->andThrow($apiException);

        Log::shouldReceive('error')->once();

        try {
            $this->transport->search('SELECT campaign.id FROM campaign');
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            // Non-string message is treated as empty, falls back to top-level; format: "{serviceName}: {message}"
            $this->assertSame('Google Ads: Top level fallback', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Create an ApiException with the given code.
     *
     * Uses real ApiException for simple cases without custom metadata.
     */
    private function createApiException(int $code): ApiException
    {
        return new ApiException(
            message: 'API error occurred',
            code: $code,
            status: 'STATUS',
        );
    }

    /**
     * Create a partial mock ApiException with custom metadata.
     *
     * Uses Mockery's partial mock to keep real ApiException behavior
     * while mocking only getMetadata() for testing retry-after parsing.
     */
    private function createApiExceptionWithMetadata(int $code, array $metadata): ApiException&MockInterface
    {
        $mock = Mockery::mock(
            ApiException::class . '[getMetadata]',
            ['API error occurred', $code, 'STATUS'],
        );
        $mock->shouldReceive('getMetadata')->andReturn($metadata);

        return $mock;
    }
}
