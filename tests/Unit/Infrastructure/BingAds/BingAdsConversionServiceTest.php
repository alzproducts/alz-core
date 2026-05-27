<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\BingAds;

use App\Application\Conversion\BingConversionUploadDTO;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\BingAds\BingAdsConfig;
use App\Infrastructure\BingAds\BingAdsConversionClient;
use App\Infrastructure\BingAds\BingAdsConversionService;
use App\Infrastructure\BingAds\BingAdsConversionTransport;
use App\Infrastructure\Phone\PhoneNormalisationService;
use DateTimeImmutable;
use InvalidArgumentException;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\ApplyOfflineConversionsRequest;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\OfflineConversion;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(BingAdsConversionService::class)]
#[CoversClass(BingAdsConversionClient::class)]
final class BingAdsConversionServiceTest extends TestCase
{
    private const string GOAL_NAME = 'Lead Received';

    private BingAdsConversionTransport&MockInterface $mockTransport;
    private BingAdsConversionService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = new BingAdsConfig(
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            refreshToken: 'test-refresh-token',
            developerToken: 'test-developer-token',
            accountId: 'test-account-id',
            customerId: 'test-customer-id',
            offlineLeadConversionGoalName: self::GOAL_NAME,
        );

        $this->mockTransport = Mockery::mock(BingAdsConversionTransport::class);
        $client = new BingAdsConversionClient($this->mockTransport);
        $this->service = new BingAdsConversionService($client, $config, new PhoneNormalisationService());
    }

    #[Test]
    public function it_builds_offline_conversion_with_correct_msclkid(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            ),
        );

        $this->assertSame('msclk_abc123', $this->firstConversion($conversion)->getMicrosoftClickId());
    }

    #[Test]
    public function it_builds_offline_conversion_with_correct_goal_name(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            ),
        );

        $this->assertSame(self::GOAL_NAME, $this->firstConversion($conversion)->getConversionName());
    }

    #[Test]
    public function it_formats_datetime_in_utc(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 11:30:45+01:00'),
            ),
        );

        $this->assertSame('2026-05-16T10:30:45Z', $this->firstConversion($conversion)->getConversionTime());
    }

    #[Test]
    public function it_hashes_and_normalises_email(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: '  User.Name+alias@Gmail.COM  ',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            ),
        );

        $expectedHash = \hash('sha256', 'username@gmail.com');
        $this->assertSame($expectedHash, $this->firstConversion($conversion)->getHashedEmailAddress());
    }

    #[Test]
    public function it_includes_hashed_phone_when_valid(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                phone: '07911 123456',
            ),
        );

        $this->assertSame(\hash('sha256', '+447911123456'), $this->firstConversion($conversion)->getHashedPhoneNumber());
    }

    #[Test]
    public function it_omits_phone_when_null(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                phone: null,
            ),
        );

        $this->assertNull($this->firstConversion($conversion)->getHashedPhoneNumber());
    }

    #[Test]
    public function it_omits_phone_when_unnormalisable(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                phone: 'not-a-phone-number',
            ),
        );

        $this->assertNull($this->firstConversion($conversion)->getHashedPhoneNumber());
    }

    #[Test]
    public function it_omits_email_when_null_and_includes_phone(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: null,
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                phone: '07911 123456',
            ),
        );

        $this->assertNull($this->firstConversion($conversion)->getHashedEmailAddress());
        $this->assertSame(\hash('sha256', '+447911123456'), $this->firstConversion($conversion)->getHashedPhoneNumber());
    }

    #[Test]
    public function it_throws_when_email_is_empty_string(): void
    {
        $this->mockTransport->shouldNotReceive('applyOfflineConversion');

        $this->expectException(InvalidArgumentException::class);

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: '',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                phone: '07911 123456',
            ),
        );
    }

    #[Test]
    public function it_throws_when_neither_email_nor_phone_provided(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BingConversionUploadDTO(
            msclkid: 'msclk_abc123',
            email: null,
            convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            phone: null,
        );
    }

    #[Test]
    public function it_throws_when_email_null_and_phone_unnormalisable(): void
    {
        $this->mockTransport->shouldNotReceive('applyOfflineConversion');

        $this->expectException(InvalidArgumentException::class);

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: null,
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                phone: 'not-a-phone-number',
            ),
        );
    }

    #[Test]
    public function it_sets_conversion_value_fields_when_money_provided(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: Money::exclusive(150.00, 'GBP'),
            ),
        );

        $this->assertSame(150.00, $this->firstConversion($conversion)->getConversionValue());
        $this->assertSame('GBP', $this->firstConversion($conversion)->getConversionCurrencyCode());
    }

    #[Test]
    public function it_omits_value_fields_when_null(): void
    {
        $conversion = $this->captureConversion();

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
                value: null,
            ),
        );

        $this->assertNull($this->firstConversion($conversion)->getConversionValue());
        $this->assertNull($this->firstConversion($conversion)->getConversionCurrencyCode());
    }

    #[Test]
    public function it_throws_unsupported_conversion_type(): void
    {
        $this->mockTransport->shouldNotReceive('applyOfflineConversion');

        $this->expectException(UnsupportedConversionTypeException::class);

        $this->service->uploadOfflineConversion(
            ConversionType::QuoteIssued,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            ),
        );
    }

    #[Test]
    public function it_propagates_authentication_expired_exception(): void
    {
        $this->mockTransport
            ->shouldReceive('applyOfflineConversion')
            ->andThrow(new AuthenticationExpiredException('Bing Ads', 'token expired'));

        $this->expectException(AuthenticationExpiredException::class);

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            ),
        );
    }

    #[Test]
    public function it_propagates_external_service_unavailable_exception(): void
    {
        $this->mockTransport
            ->shouldReceive('applyOfflineConversion')
            ->andThrow(new ExternalServiceUnavailableException('Bing Ads', 60));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            ),
        );
    }

    #[Test]
    public function it_propagates_invalid_api_request_exception(): void
    {
        $this->mockTransport
            ->shouldReceive('applyOfflineConversion')
            ->andThrow(new InvalidApiRequestException('Bing Ads', 'invalid click id'));

        $this->expectException(InvalidApiRequestException::class);

        $this->service->uploadOfflineConversion(
            ConversionType::LeadReceived,
            new BingConversionUploadDTO(
                msclkid: 'msclk_abc123',
                email: 'user@example.com',
                convertedAt: new DateTimeImmutable('2026-05-16 10:30:00+00:00'),
            ),
        );
    }

    private function captureConversion(): object
    {
        $capture = new class () {
            public ?OfflineConversion $model = null;
        };

        $this->mockTransport
            ->shouldReceive('applyOfflineConversion')
            ->once()
            ->withArgs(static function (ApplyOfflineConversionsRequest $request) use ($capture): bool {
                $conversions = $request->getOfflineConversions();
                $capture->model = $conversions[0];

                return true;
            });

        return $capture;
    }

    private function firstConversion(object $capture): OfflineConversion
    {
        $this->assertNotNull($capture->model, 'Transport was not invoked');

        return $capture->model;
    }
}
