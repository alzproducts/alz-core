<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\Infrastructure\GoogleAds\GoogleAdsTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use Google\Ads\GoogleAds\Lib\V25\GoogleAdsClient as SdkGoogleAdsClient;
use Google\Ads\GoogleAds\V25\Errors\ConversionUploadErrorEnum\ConversionUploadError;
use Google\Ads\GoogleAds\V25\Errors\ErrorCode;
use Google\Ads\GoogleAds\V25\Errors\GoogleAdsError;
use Google\Ads\GoogleAds\V25\Errors\GoogleAdsFailure;
use Google\Ads\GoogleAds\V25\Services\Client\ConversionUploadServiceClient;
use Google\Ads\GoogleAds\V25\Services\UploadClickConversionsRequest;
use Google\Ads\GoogleAds\V25\Services\UploadClickConversionsResponse;
use Google\ApiCore\ApiException;
use Google\Protobuf\Any;
use Google\Rpc\Code;
use Google\Rpc\Status;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * GoogleAdsTransport — uploadClickConversion() unit tests.
 *
 * Mocks the Google Ads SDK client/service client; uses real protobuf response
 * + Status objects (per tests/CLAUDE.md — mocking protobufs causes segfaults).
 * ApiException metadata is injected via Reflection (the protected field has no setter).
 */
#[CoversClass(GoogleAdsTransport::class)]
final class GoogleAdsConversionTransportTest extends TestCase
{
    private SdkGoogleAdsClient&MockInterface $mockSdkClient;
    private ConversionUploadServiceClient&MockInterface $mockServiceClient;
    private GoogleAdsTransport $transport;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = new GoogleAdsConfig(
            clientId: 'oauth-client-id',
            clientSecret: 'oauth-client-secret',
            refreshToken: 'oauth-refresh-token',
            developerToken: 'developer-token',
            customerId: '1234567890',
        );

        $this->mockSdkClient = Mockery::mock(SdkGoogleAdsClient::class);
        $this->mockServiceClient = Mockery::mock(ConversionUploadServiceClient::class);

        $this->mockSdkClient
            ->shouldReceive('getConversionUploadServiceClient')
            ->andReturn($this->mockServiceClient);

        $this->transport = new GoogleAdsTransport($this->mockSdkClient, $config, \app(TransientLogThrottle::class));
    }

    #[Test]
    public function it_succeeds_when_partial_failure_error_is_null(): void
    {
        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->once()
            ->andReturn(new UploadClickConversionsResponse());

        $this->transport->uploadClickConversion(new UploadClickConversionsRequest());

        $this->assertTrue(true);
    }

    #[Test]
    public function it_succeeds_when_partial_failure_error_code_is_zero(): void
    {
        $status = new Status();
        $status->setCode(0);
        $status->setMessage('OK');

        $response = new UploadClickConversionsResponse();
        $response->setPartialFailureError($status);

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->once()
            ->andReturn($response);

        $this->transport->uploadClickConversion(new UploadClickConversionsRequest());

        $this->assertTrue(true);
    }

    #[Test]
    public function it_translates_partial_failure_error_to_invalid_api_request_exception(): void
    {
        $status = new Status();
        $status->setCode(3);
        $status->setMessage('Conversion failed: GCLID expired');

        $response = new UploadClickConversionsResponse();
        $response->setPartialFailureError($status);

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->andReturn($response);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'partial failure')
                && ($context['code'] ?? null) === 3
                && ($context['message'] ?? null) === 'Conversion failed: GCLID expired');

        $this->expectException(InvalidApiRequestException::class);
        $this->expectExceptionMessage('API request validation failed');

        $this->transport->uploadClickConversion(new UploadClickConversionsRequest());
    }

    #[Test]
    public function it_translates_resource_exhausted_to_external_service_unavailable_with_retry_after(): void
    {
        $exception = new ApiException('rate limited', Code::RESOURCE_EXHAUSTED, 'RESOURCE_EXHAUSTED');
        $this->setExceptionMetadata($exception, ['retry-after' => '180']);

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->andThrow($exception);

        try {
            $this->transport->uploadClickConversion(new UploadClickConversionsRequest());
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame(180, $e->retryAfter);
            $this->assertSame($exception, $e->getPrevious());
        }
    }

    #[Test]
    public function it_translates_permission_denied_to_authentication_expired(): void
    {
        $exception = new ApiException('permission denied', Code::PERMISSION_DENIED, 'PERMISSION_DENIED');

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->andThrow($exception);

        $this->expectException(AuthenticationExpiredException::class);

        $this->transport->uploadClickConversion(new UploadClickConversionsRequest());
    }

    #[Test]
    public function it_translates_unauthenticated_to_authentication_expired(): void
    {
        $exception = new ApiException('unauthenticated', Code::UNAUTHENTICATED, 'UNAUTHENTICATED');

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->andThrow($exception);

        $this->expectException(AuthenticationExpiredException::class);

        $this->transport->uploadClickConversion(new UploadClickConversionsRequest());
    }

    #[Test]
    public function it_translates_other_api_exceptions_to_external_service_unavailable(): void
    {
        $exception = new ApiException('internal error', Code::INTERNAL, 'INTERNAL');

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport->uploadClickConversion(new UploadClickConversionsRequest());
    }

    #[Test]
    public function it_appends_decoded_error_code_names_to_the_partial_failure_detail(): void
    {
        $response = new UploadClickConversionsResponse();
        $response->setPartialFailureError(
            self::statusWithPackedFailure('Errors in mutate operation.', ConversionUploadError::UNPARSEABLE_GCLID),
        );

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->andReturn($response);

        Log::shouldReceive('error')->once();

        try {
            $this->transport->uploadClickConversion(new UploadClickConversionsRequest());
            $this->fail('Expected InvalidApiRequestException');
        } catch (InvalidApiRequestException $e) {
            $this->assertSame('Errors in mutate operation. [UNPARSEABLE_GCLID]', $e->detail);
        }
    }

    #[Test]
    public function it_degrades_to_the_status_message_when_the_details_cannot_be_decoded(): void
    {
        $undecodable = new Any();
        $undecodable->setTypeUrl('type.googleapis.com/not.a.registered.Message');
        $undecodable->setValue('not-a-protobuf');

        $status = new Status();
        $status->setCode(3);
        $status->setMessage('Errors in mutate operation.');
        $status->setDetails([$undecodable]);

        $response = new UploadClickConversionsResponse();
        $response->setPartialFailureError($status);

        $this->mockServiceClient
            ->shouldReceive('uploadClickConversions')
            ->andReturn($response);

        Log::shouldReceive('error')->once();

        try {
            $this->transport->uploadClickConversion(new UploadClickConversionsRequest());
            $this->fail('Expected InvalidApiRequestException');
        } catch (InvalidApiRequestException $e) {
            $this->assertSame('Errors in mutate operation.', $e->detail);
        }
    }

    /**
     * Build the real partial-failure shape: a Status whose details carry a packed
     * GoogleAdsFailure, exactly as the API returns it.
     */
    private static function statusWithPackedFailure(string $message, int $conversionUploadError): Status
    {
        $errorCode = new ErrorCode();
        $errorCode->setConversionUploadError($conversionUploadError);

        $error = new GoogleAdsError();
        $error->setErrorCode($errorCode);
        $error->setMessage('The click ID is invalid.');

        $failure = new GoogleAdsFailure();
        $failure->setErrors([$error]);

        $any = new Any();
        $any->pack($failure);

        $status = new Status();
        $status->setCode(3);
        $status->setMessage($message);
        $status->setDetails([$any]);

        return $status;
    }

    /**
     * @param array<string, string> $metadata
     */
    private function setExceptionMetadata(ApiException $exception, array $metadata): void
    {
        $prop = new ReflectionProperty($exception, 'metadata');
        $prop->setValue($exception, $metadata);
    }
}
