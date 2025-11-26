<?php

declare(strict_types=1);

namespace Tests\Feature\Application\AdSpend\UseCases;

use App\Application\AdSpend\UseCases\SyncCampaignLookupTableUseCase;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\UnexpectedApiResultException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncCampaignLookupTableUseCase::class)]
final class SyncCampaignLookupTableUseCaseTest extends TestCase
{
    private GoogleAdsClientInterface&MockInterface $googleAdsClient;

    private MixpanelClientInterface&MockInterface $mixpanelClient;

    private LoggerInterface&MockInterface $loggerMock;

    private SyncCampaignLookupTableUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleAdsClient = Mockery::mock(GoogleAdsClientInterface::class);
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);
        $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SyncCampaignLookupTableUseCase(
            $this->googleAdsClient,
            $this->mixpanelClient,
            $this->loggerMock,
        );
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_syncs_single_campaign_to_mixpanel(): void
    {
        $campaign = new Campaign(
            id: 123456789,
            name: '[01] Search - Branded',
            status: 'ENABLED',
        );

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->withArgs(static function (array $campaigns): bool {
                self::assertCount(1, $campaigns);
                self::assertInstanceOf(Campaign::class, $campaigns[0]);
                self::assertSame(123456789, $campaigns[0]->campaignId);
                self::assertSame('[01] Search - Branded', $campaigns[0]->campaignName);
                self::assertSame('ENABLED', $campaigns[0]->status);

                return true;
            });

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting campaign lookup table sync')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Retrieved campaigns from Google Ads', ['campaign_count' => 1])
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Campaign lookup table sync completed', ['campaigns_synced' => 1])
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_syncs_multiple_campaigns_to_mixpanel(): void
    {
        $campaigns = [
            new Campaign(id: 111, name: 'Campaign One', status: 'ENABLED'),
            new Campaign(id: 222, name: 'Campaign Two', status: 'PAUSED'),
            new Campaign(id: 333, name: 'Campaign Three', status: 'REMOVED'),
            new Campaign(id: 444, name: 'Campaign Four', status: 'UNSPECIFIED'),
            new Campaign(id: 555, name: 'Campaign Five', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->withArgs(static function (array $receivedCampaigns): bool {
                self::assertCount(5, $receivedCampaigns);
                self::assertSame(111, $receivedCampaigns[0]->campaignId);
                self::assertSame(222, $receivedCampaigns[1]->campaignId);
                self::assertSame(333, $receivedCampaigns[2]->campaignId);
                self::assertSame(444, $receivedCampaigns[3]->campaignId);
                self::assertSame(555, $receivedCampaigns[4]->campaignId);

                return true;
            });

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Campaign lookup table sync completed', ['campaigns_synced' => 5])
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_preserves_campaign_order(): void
    {
        $campaigns = [
            new Campaign(id: 999, name: 'Last', status: 'ENABLED'),
            new Campaign(id: 111, name: 'First', status: 'ENABLED'),
            new Campaign(id: 555, name: 'Middle', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->withArgs(static function (array $receivedCampaigns): bool {
                // Verify order is preserved (not alphabetically sorted)
                self::assertSame(999, $receivedCampaigns[0]->campaignId);
                self::assertSame(111, $receivedCampaigns[1]->campaignId);
                self::assertSame(555, $receivedCampaigns[2]->campaignId);

                return true;
            });

        $this->useCase->execute();
    }

    // ========================================================================
    // Empty Results Handling
    // ========================================================================

    #[Test]
    public function it_throws_exception_when_no_campaigns_found(): void
    {
        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('replaceCampaignLookupTable');

        $this->loggerMock
            ->shouldReceive('error')
            ->with('No campaigns found in Google Ads - this may indicate an API issue or account misconfiguration')
            ->once();

        $this->expectException(UnexpectedApiResultException::class);
        $this->expectExceptionMessage('Unexpected result from Google Ads');

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_error_and_does_not_call_mixpanel_when_no_campaigns_found(): void
    {
        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('replaceCampaignLookupTable');

        $this->loggerMock
            ->shouldReceive('error')
            ->with('No campaigns found in Google Ads - this may indicate an API issue or account misconfiguration')
            ->once();

        try {
            $this->useCase->execute();
        } catch (UnexpectedApiResultException) {
            // Expected
        }
    }

    // ========================================================================
    // Google Ads Client Exceptions
    // ========================================================================

    #[Test]
    public function it_propagates_google_ads_api_exception(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('replaceCampaignLookupTable');

        $this->expectExceptionObject($exception);

        $this->useCase->execute();
    }

    #[Test]
    public function it_propagates_external_service_exception_from_google_ads(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('replaceCampaignLookupTable');

        try {
            $this->useCase->execute();
            self::fail('Expected ExternalServiceUnavailableException to be thrown');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertStringContainsString('Google Ads', $e->getMessage());
        }
    }

    #[Test]
    public function it_logs_start_before_google_ads_exception(): void
    {
        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting campaign lookup table sync')
            ->once();

        try {
            $this->useCase->execute();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    // ========================================================================
    // Mixpanel Client Exceptions
    // ========================================================================

    #[Test]
    public function it_propagates_mixpanel_api_exception(): void
    {
        $campaigns = [
            new Campaign(id: 123, name: 'Test', status: 'ENABLED'),
        ];

        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage('Mixpanel');

        $this->useCase->execute();
    }

    #[Test]
    public function it_propagates_external_service_exception_from_mixpanel(): void
    {
        $campaigns = [
            new Campaign(id: 123, name: 'Test', status: 'ENABLED'),
        ];

        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->andThrow($exception);

        try {
            $this->useCase->execute();
            self::fail('Expected ExternalServiceUnavailableException to be thrown');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertStringContainsString('Mixpanel', $e->getMessage());
        }
    }

    #[Test]
    public function it_does_not_log_completion_when_mixpanel_fails(): void
    {
        $campaigns = [
            new Campaign(id: 123, name: 'Test', status: 'ENABLED'),
        ];

        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting campaign lookup table sync')
            ->once();

        try {
            $this->useCase->execute();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    // ========================================================================
    // Data Integrity Tests
    // ========================================================================

    #[Test]
    public function it_passes_campaigns_unchanged_to_mixpanel(): void
    {
        $campaign = new Campaign(
            id: 987654321,
            name: '[02] Performance Max',
            status: 'PAUSED',
        );

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->withArgs(static function (array $campaigns): bool {
                self::assertSame(987654321, $campaigns[0]->campaignId);
                self::assertSame('[02] Performance Max', $campaigns[0]->campaignName);
                self::assertSame('PAUSED', $campaigns[0]->status);

                return true;
            });

        $this->useCase->execute();
    }

    #[Test]
    public function it_handles_campaign_with_special_characters(): void
    {
        $specialName = '[01] Search - Branded | Q4 2024 & Premium';
        $campaign = new Campaign(
            id: 123,
            name: $specialName,
            status: 'ENABLED',
        );

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->withArgs(static function (array $campaigns) use ($specialName): bool {
                self::assertSame($specialName, $campaigns[0]->campaignName);

                return true;
            });

        $this->useCase->execute();
    }

    #[Test]
    public function it_handles_all_campaign_status_values(): void
    {

        $campaigns = [
            new Campaign(id: 111, name: 'Enabled', status: 'ENABLED'),
            new Campaign(id: 222, name: 'Paused', status: 'PAUSED'),
            new Campaign(id: 333, name: 'Removed', status: 'REMOVED'),
            new Campaign(id: 444, name: 'Unspecified', status: 'UNSPECIFIED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->withArgs(static function (array $receivedCampaigns): bool {
                self::assertSame('ENABLED', $receivedCampaigns[0]->status);
                self::assertSame('PAUSED', $receivedCampaigns[1]->status);
                self::assertSame('REMOVED', $receivedCampaigns[2]->status);
                self::assertSame('UNSPECIFIED', $receivedCampaigns[3]->status);

                return true;
            });

        $this->useCase->execute();
    }

    // ========================================================================
    // Logging Verification
    // ========================================================================

    #[Test]
    public function it_logs_start_message(): void
    {
        $campaigns = [
            new Campaign(id: 1, name: 'Search Campaign', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting campaign lookup table sync')
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_completion_with_exact_campaign_count(): void
    {
        $campaigns = [
            new Campaign(id: 1, name: 'One', status: 'ENABLED'),
            new Campaign(id: 2, name: 'Two', status: 'ENABLED'),
            new Campaign(id: 3, name: 'Three', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Campaign lookup table sync completed', ['campaigns_synced' => 3])
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_retrieved_campaigns_count(): void
    {
        $campaigns = [
            new Campaign(id: 1, name: 'One', status: 'ENABLED'),
            new Campaign(id: 2, name: 'Two', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn($campaigns);

        $this->mixpanelClient
            ->shouldReceive('replaceCampaignLookupTable')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Retrieved campaigns from Google Ads', ['campaign_count' => 2])
            ->once();

        $this->useCase->execute();
    }
}
