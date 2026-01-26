<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console;

use App\Application\Contracts\BingAdsClientInterface;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\HelpScout\ConnectivityClientInterface as HelpScoutConnectivityClient;
use App\Application\Contracts\Linnworks\ConnectivityClientInterface as LinnworksConnectivityClient;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface as ShopwiredConnectivityClient;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Presentation\Console\Commands\VerifyApiConnectivityCommand;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VerifyApiConnectivityCommand Feature Tests
 *
 * Smoke tests for the verify:api console command. Tests command orchestration,
 * not individual client verification (that's Infrastructure layer concern).
 */
#[CoversClass(VerifyApiConnectivityCommand::class)]
final class VerifyApiConnectivityCommandTest extends TestCase
{
    private MockInterface&ReviewsIoClientInterface $reviewsIoClient;

    private MockInterface&MixpanelClientInterface $mixpanelClient;

    private MockInterface&GoogleAdsClientInterface $googleAdsClient;

    private MockInterface&BingAdsClientInterface $bingAdsClient;

    private MockInterface&ShopwiredConnectivityClient $shopwiredClient;

    private MockInterface&LinnworksConnectivityClient $linnworksClient;

    private MockInterface&HelpScoutConnectivityClient $helpscoutClient;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewsIoClient = Mockery::mock(ReviewsIoClientInterface::class);
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);
        $this->googleAdsClient = Mockery::mock(GoogleAdsClientInterface::class);
        $this->bingAdsClient = Mockery::mock(BingAdsClientInterface::class);
        $this->shopwiredClient = Mockery::mock(ShopwiredConnectivityClient::class);
        $this->linnworksClient = Mockery::mock(LinnworksConnectivityClient::class);
        $this->helpscoutClient = Mockery::mock(HelpScoutConnectivityClient::class);

        $this->app->instance(ReviewsIoClientInterface::class, $this->reviewsIoClient);
        $this->app->instance(MixpanelClientInterface::class, $this->mixpanelClient);
        $this->app->instance(GoogleAdsClientInterface::class, $this->googleAdsClient);
        $this->app->instance(BingAdsClientInterface::class, $this->bingAdsClient);
        $this->app->instance(ShopwiredConnectivityClient::class, $this->shopwiredClient);
        $this->app->instance(LinnworksConnectivityClient::class, $this->linnworksClient);
        $this->app->instance(HelpScoutConnectivityClient::class, $this->helpscoutClient);
    }

    #[Test]
    public function it_verifies_all_clients_successfully(): void
    {
        $this->reviewsIoClient->shouldReceive('verifyConnectivity')->once();
        $this->mixpanelClient->shouldReceive('verifyConnectivity')->once();
        $this->googleAdsClient->shouldReceive('verifyConnectivity')->once();
        $this->bingAdsClient->shouldReceive('verifyConnectivity')->once();
        $this->shopwiredClient->shouldReceive('verifyConnectivity')->once();
        $this->linnworksClient->shouldReceive('verifyConnectivity')->once();
        $this->helpscoutClient->shouldReceive('verifyConnectivity')->once();

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Verifying Reviews.io...')
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutput('Verifying Bing Ads...')
            ->expectsOutput('Verifying Shopwired...')
            ->expectsOutput('Verifying Linnworks...')
            ->expectsOutput('Verifying HelpScout...')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_failure_when_client_fails(): void
    {
        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Mixpanel'));

        $this->artisan('verify:api', ['client' => 'mixpanel'])
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutputToContain('Failed:')
            ->expectsOutput('Some API clients failed: mixpanel')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_returns_failure_for_unknown_client(): void
    {
        $this->artisan('verify:api', ['client' => 'unknown'])
            ->expectsOutput('Unknown client: unknown')
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, bingads, shopwired, linnworks, helpscout, all')
            ->assertExitCode(Command::FAILURE);
    }
}
