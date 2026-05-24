<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds;

use App\Application\Conversion\GoogleConversionUploadDTO;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\Infrastructure\GoogleAds\GoogleAdsConversionClient;
use App\Infrastructure\GoogleAds\GoogleAdsConversionService;
use App\Infrastructure\GoogleAds\GoogleAdsTransport;
use App\Infrastructure\Phone\PhoneNormalisationService;
use DateTimeImmutable;
use Google\Ads\GoogleAds\V22\Services\ClickConversion;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsRequest;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(GoogleAdsConversionService::class)]
#[CoversClass(GoogleAdsConversionClient::class)]
final class GoogleAdsConversionServiceTest extends TestCase
{
    private const string CUSTOMER_ID = '1234567890';
    private const string LEAD_ACTION_ID = '9000000001';
    private const string QUOTE_ACTION_ID = '9000000002';

    private GoogleAdsTransport&MockInterface $mockTransport;
    private GoogleAdsConversionService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = new GoogleAdsConfig(
            clientId: 'oauth-client-id',
            clientSecret: 'oauth-client-secret',
            refreshToken: 'oauth-refresh-token',
            developerToken: 'developer-token',
            customerId: self::CUSTOMER_ID,
            leadConversionActionId: self::LEAD_ACTION_ID,
            quoteConversionActionId: self::QUOTE_ACTION_ID,
        );

        $this->mockTransport = Mockery::mock(GoogleAdsTransport::class);
        $client = new GoogleAdsConversionClient($this->mockTransport, $config);
        $this->service = new GoogleAdsConversionService($client, $config, new PhoneNormalisationService());
    }

    #[Test]
    public function it_builds_click_conversion_with_correct_gclid(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKCAjw1234567890abcdef',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $conversion = $this->firstConversion($captured);
        $this->assertSame('CjwKCAjw1234567890abcdef', $conversion->getGclid());
    }

    #[Test]
    public function it_builds_click_conversion_with_correct_action_resource_name(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $conversion = $this->firstConversion($captured);
        $this->assertSame(
            'customers/' . self::CUSTOMER_ID . '/conversionActions/' . self::LEAD_ACTION_ID,
            $conversion->getConversionAction(),
        );
    }

    #[Test]
    public function it_hashes_and_normalises_email_before_sending(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: '  USER@Example.COM  ',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $expectedHash = \hash('sha256', 'user@example.com');
        $identifiers = \iterator_to_array($this->firstConversion($captured)->getUserIdentifiers());
        $this->assertCount(1, $identifiers);
        $this->assertSame($expectedHash, $identifiers[0]->getHashedEmail());
    }

    #[Test]
    public function it_sets_conversion_value_and_currency_when_money_provided(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::QuoteIssued,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: Money::exclusive(100.00, 'GBP'),
            ),
        );

        $conversion = $this->firstConversion($captured);
        $this->assertSame(100.00, $conversion->getConversionValue());
        $this->assertSame('GBP', $conversion->getCurrencyCode());
    }

    #[Test]
    public function it_extracts_net_value_when_money_is_inclusive(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::QuoteIssued,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: Money::inclusive(120.00, 'GBP'),
            ),
        );

        $this->assertSame(100.00, $this->firstConversion($captured)->getConversionValue());
    }

    #[Test]
    public function it_does_not_set_conversion_value_when_money_is_null(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $conversion = $this->firstConversion($captured);
        $this->assertSame(0.0, $conversion->getConversionValue());
        $this->assertSame('', $conversion->getCurrencyCode());
    }

    #[Test]
    public function it_formats_datetime_in_google_ads_format(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:45+01:00'),
                value: null,
            ),
        );

        $this->assertSame(
            '2026-05-16 10:30:45+01:00',
            $this->firstConversion($captured)->getConversionDateTime(),
        );
    }

    #[Test]
    public function it_sets_partial_failure_to_true(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $this->assertTrue($captured->request?->getPartialFailure());
    }

    #[Test]
    public function it_sets_customer_id_on_request(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $this->assertSame(self::CUSTOMER_ID, $captured->request?->getCustomerId());
    }

    #[Test]
    public function it_maps_lead_received_type_to_lead_conversion_action_id(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $this->assertStringContainsString(
            '/conversionActions/' . self::LEAD_ACTION_ID,
            $this->firstConversion($captured)->getConversionAction(),
        );
    }

    #[Test]
    public function it_maps_quote_issued_type_to_quote_conversion_action_id(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::QuoteIssued,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $this->assertStringContainsString(
            '/conversionActions/' . self::QUOTE_ACTION_ID,
            $this->firstConversion($captured)->getConversionAction(),
        );
    }

    #[Test]
    public function it_throws_when_gclid_is_empty(): void
    {
        $this->mockTransport->shouldNotReceive('uploadClickConversion');

        $this->expectException(InvalidArgumentException::class);

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: '',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );
    }

    #[Test]
    public function it_throws_when_email_is_empty(): void
    {
        $this->mockTransport->shouldNotReceive('uploadClickConversion');

        $this->expectException(InvalidArgumentException::class);

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: '',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );
    }

    #[Test]
    public function it_propagates_authentication_expired_exception(): void
    {
        $this->mockTransport
            ->shouldReceive('uploadClickConversion')
            ->andThrow(new AuthenticationExpiredException('Google Ads', 'token expired'));

        $this->expectException(AuthenticationExpiredException::class);

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );
    }

    #[Test]
    public function it_propagates_external_service_unavailable_exception(): void
    {
        $this->mockTransport
            ->shouldReceive('uploadClickConversion')
            ->andThrow(new ExternalServiceUnavailableException('Google Ads', 60));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );
    }

    #[Test]
    public function it_propagates_invalid_api_request_exception(): void
    {
        $this->mockTransport
            ->shouldReceive('uploadClickConversion')
            ->andThrow(new InvalidApiRequestException('Google Ads', 'expired gclid'));

        $this->expectException(InvalidApiRequestException::class);

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );
    }

    #[Test]
    public function it_adds_hashed_phone_identifier_when_valid_phone_provided(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
                phone: '07911 123456',
            ),
        );

        $identifiers = \iterator_to_array($this->firstConversion($captured)->getUserIdentifiers());
        $this->assertCount(2, $identifiers);
        $this->assertSame(\hash('sha256', 'user@example.com'), $identifiers[0]->getHashedEmail());
        $this->assertSame(\hash('sha256', '+447911123456'), $identifiers[1]->getHashedPhoneNumber());
    }

    #[Test]
    public function it_omits_phone_identifier_when_phone_is_null(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
                phone: null,
            ),
        );

        $identifiers = \iterator_to_array($this->firstConversion($captured)->getUserIdentifiers());
        $this->assertCount(1, $identifiers);
    }

    #[Test]
    public function it_omits_phone_identifier_when_phone_cannot_be_normalised(): void
    {
        $captured = $this->captureUploadRequest();

        $this->service->uploadConversion(
            ConversionType::LeadReceived,
            new GoogleConversionUploadDTO(
                gclid: 'CjwKgclid',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
                phone: 'not-a-phone-number',
            ),
        );

        $identifiers = \iterator_to_array($this->firstConversion($captured)->getUserIdentifiers());
        $this->assertCount(1, $identifiers);
    }

    /**
     * Capture the UploadClickConversionsRequest passed to the transport mock.
     *
     * Returns an object whose `request` property is populated when the service invokes
     * the client/transport chain — supports asserting on the request after the call.
     */
    private function captureUploadRequest(): object
    {
        $capture = new class () {
            public ?UploadClickConversionsRequest $request = null;
        };

        $this->mockTransport
            ->shouldReceive('uploadClickConversion')
            ->once()
            ->withArgs(static function (UploadClickConversionsRequest $request) use ($capture): bool {
                $capture->request = $request;

                return true;
            });

        return $capture;
    }

    /**
     * Pull the first ClickConversion out of a captured request.
     */
    private function firstConversion(object $capture): ClickConversion
    {
        $this->assertNotNull($capture->request, 'Transport was not invoked');

        $conversions = \iterator_to_array($capture->request->getConversions());
        $this->assertCount(1, $conversions);

        return $conversions[0];
    }
}
