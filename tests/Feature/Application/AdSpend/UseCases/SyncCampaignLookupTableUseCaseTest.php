<?php

declare(strict_types=1);

namespace Tests\Feature\Application\AdSpend\UseCases;

use App\Application\AdSpend\UseCases\SyncCampaignLookupTableUseCase;
use App\Domain\AdSpend\Contracts\GoogleAdsClientInterface;
use App\Domain\AdSpend\Contracts\MixpanelCampaignLookupClientInterface;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use App\Domain\AdSpend\ValueObjects\Campaign;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SyncCampaignLookupTableUseCase::class)]
final class SyncCampaignLookupTableUseCaseTest extends TestCase
{
    private GoogleAdsClientInterface&MockInterface $googleAdsClient;

    private MixpanelCampaignLookupClientInterface&MockInterface $mixpanelLookupTable;

    private SyncCampaignLookupTableUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleAdsClient = Mockery::mock(GoogleAdsClientInterface::class);
        $this->mixpanelLookupTable = Mockery::mock(MixpanelCampaignLookupClientInterface::class);

        $this->useCase = new SyncCampaignLookupTableUseCase(
            $this->googleAdsClient,
            $this->mixpanelLookupTable,
        );
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_syncs_single_campaign_to_mixpanel(): void
    {
        Log::spy();

        $campaign = new Campaign(
            campaignId: 123456789,
            campaignName: '[01] Search - Branded',
            status: 'ENABLED',
        );

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelLookupTable
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

        $this->useCase->execute();

        Log::shouldHaveReceived('info')
            ->with('Starting campaign lookup table sync');

        Log::shouldHaveReceived('info')
            ->with('Retrieved campaigns from Google Ads', ['campaign_count' => 1]);

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync completed', ['campaigns_synced' => 1]);
    }

    #[Test]
    public function it_syncs_multiple_campaigns_to_mixpanel(): void
    {
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 111, campaignName: 'Campaign One', status: 'ENABLED'),
            new Campaign(campaignId: 222, campaignName: 'Campaign Two', status: 'PAUSED'),
            new Campaign(campaignId: 333, campaignName: 'Campaign Three', status: 'REMOVED'),
            new Campaign(campaignId: 444, campaignName: 'Campaign Four', status: 'UNSPECIFIED'),
            new Campaign(campaignId: 555, campaignName: 'Campaign Five', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
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

        $this->useCase->execute();

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync completed', ['campaigns_synced' => 5]);
    }

    #[Test]
    public function it_preserves_campaign_order(): void
    {
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 999, campaignName: 'Last', status: 'ENABLED'),
            new Campaign(campaignId: 111, campaignName: 'First', status: 'ENABLED'),
            new Campaign(campaignId: 555, campaignName: 'Middle', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
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
    public function it_handles_empty_results_from_google_ads(): void
    {
        Log::spy();

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $this->mixpanelLookupTable
            ->shouldNotReceive('replaceCampaignLookupTable');

        $this->useCase->execute();

        Log::shouldHaveReceived('info')
            ->with('Starting campaign lookup table sync');

        Log::shouldHaveReceived('warning')
            ->with('No campaigns found in Google Ads, clearing Mixpanel lookup table');

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync completed', ['campaigns_synced' => 0]);
    }

    #[Test]
    public function it_does_not_call_mixpanel_when_no_campaigns_found(): void
    {
        Log::spy();

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $this->mixpanelLookupTable
            ->shouldNotReceive('replaceCampaignLookupTable');

        $this->useCase->execute();
    }

    // ========================================================================
    // Google Ads Client Exceptions
    // ========================================================================

    #[Test]
    public function it_propagates_google_ads_api_exception(): void
    {
        Log::spy();

        $exception = GoogleAdsApiException::fromApiError(
            'AUTH_ERROR',
            'The user does not have access.',
        );

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $this->mixpanelLookupTable
            ->shouldNotReceive('replaceCampaignLookupTable');

        $this->expectExceptionObject($exception);

        $this->useCase->execute();
    }

    #[Test]
    public function it_propagates_api_rate_limit_exception_from_google_ads(): void
    {
        Log::spy();

        $exception = new ApiRateLimitException('Rate limit exceeded', 120);

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow($exception);

        $this->mixpanelLookupTable
            ->shouldNotReceive('replaceCampaignLookupTable');

        try {
            $this->useCase->execute();
            self::fail('Expected ApiRateLimitException to be thrown');
        } catch (ApiRateLimitException $e) {
            self::assertSame('Rate limit exceeded', $e->getMessage());
            self::assertSame(120, $e->getRetryAfter());
        }
    }

    #[Test]
    public function it_logs_start_before_google_ads_exception(): void
    {
        Log::spy();

        $exception = GoogleAdsApiException::fromApiError('API_ERROR', 'Test error');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andThrow($exception);

        try {
            $this->useCase->execute();
        } catch (GoogleAdsApiException) {
            // Expected
        }

        Log::shouldHaveReceived('info')
            ->with('Starting campaign lookup table sync');

        // Should not log completion on error
        Log::shouldNotHaveReceived('info', static fn(string $message): bool => \str_contains($message, 'completed'));
    }

    // ========================================================================
    // Mixpanel Client Exceptions
    // ========================================================================

    #[Test]
    public function it_propagates_mixpanel_api_exception(): void
    {
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 123, campaignName: 'Test', status: 'ENABLED'),
        ];

        $exception = new MixpanelApiException('Lookup table API error (400): Invalid request');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->andThrow($exception);

        $this->expectException(MixpanelApiException::class);
        $this->expectExceptionMessage('Lookup table API error');

        $this->useCase->execute();
    }

    #[Test]
    public function it_propagates_api_rate_limit_exception_from_mixpanel(): void
    {
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 123, campaignName: 'Test', status: 'ENABLED'),
        ];

        $exception = new ApiRateLimitException('Mixpanel rate limit exceeded', 30);

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
            ->shouldReceive('replaceCampaignLookupTable')
            ->once()
            ->andThrow($exception);

        try {
            $this->useCase->execute();
            self::fail('Expected ApiRateLimitException to be thrown');
        } catch (ApiRateLimitException $e) {
            self::assertSame('Mixpanel rate limit exceeded', $e->getMessage());
            self::assertSame(30, $e->getRetryAfter());
        }
    }

    #[Test]
    public function it_does_not_log_completion_when_mixpanel_fails(): void
    {
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 123, campaignName: 'Test', status: 'ENABLED'),
        ];

        $exception = new MixpanelApiException('Import failed');

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
            ->shouldReceive('replaceCampaignLookupTable')
            ->andThrow($exception);

        try {
            $this->useCase->execute();
        } catch (MixpanelApiException) {
            // Expected
        }

        Log::shouldHaveReceived('info')
            ->with('Starting campaign lookup table sync');

        Log::shouldNotHaveReceived('info', static fn(string $message): bool => \str_contains($message, 'completed'));
    }

    // ========================================================================
    // Data Integrity Tests
    // ========================================================================

    #[Test]
    public function it_passes_campaigns_unchanged_to_mixpanel(): void
    {
        Log::spy();

        $campaign = new Campaign(
            campaignId: 987654321,
            campaignName: '[02] Performance Max',
            status: 'PAUSED',
        );

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelLookupTable
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
        Log::spy();

        $specialName = '[01] Search - Branded | Q4 2024 & Premium';
        $campaign = new Campaign(
            campaignId: 123,
            campaignName: $specialName,
            status: 'ENABLED',
        );

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([$campaign]);

        $this->mixpanelLookupTable
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
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 111, campaignName: 'Enabled', status: 'ENABLED'),
            new Campaign(campaignId: 222, campaignName: 'Paused', status: 'PAUSED'),
            new Campaign(campaignId: 333, campaignName: 'Removed', status: 'REMOVED'),
            new Campaign(campaignId: 444, campaignName: 'Unspecified', status: 'UNSPECIFIED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
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
        Log::spy();

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn([]);

        $this->useCase->execute();

        Log::shouldHaveReceived('info')
            ->with('Starting campaign lookup table sync');
    }

    #[Test]
    public function it_logs_completion_with_exact_campaign_count(): void
    {
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 1, campaignName: 'One', status: 'ENABLED'),
            new Campaign(campaignId: 2, campaignName: 'Two', status: 'ENABLED'),
            new Campaign(campaignId: 3, campaignName: 'Three', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
            ->shouldReceive('replaceCampaignLookupTable')
            ->once();

        $this->useCase->execute();

        Log::shouldHaveReceived('info')
            ->with('Campaign lookup table sync completed', ['campaigns_synced' => 3]);
    }

    #[Test]
    public function it_logs_retrieved_campaigns_count(): void
    {
        Log::spy();

        $campaigns = [
            new Campaign(campaignId: 1, campaignName: 'One', status: 'ENABLED'),
            new Campaign(campaignId: 2, campaignName: 'Two', status: 'ENABLED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->andReturn($campaigns);

        $this->mixpanelLookupTable
            ->shouldReceive('replaceCampaignLookupTable')
            ->once();

        $this->useCase->execute();

        Log::shouldHaveReceived('info')
            ->with('Retrieved campaigns from Google Ads', ['campaign_count' => 2]);
    }
}
