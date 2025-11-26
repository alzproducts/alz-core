<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Presentation\Console\Commands\VerifyApiConnectivityCommand;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * VerifyApiConnectivityCommand Feature Tests
 *
 * Tests the verify:api console command covering:
 * - Individual client verification (reviewsio, mixpanel, googleads)
 * - All clients verification (all)
 * - Success and failure scenarios
 * - Invalid client argument handling
 */
#[CoversClass(VerifyApiConnectivityCommand::class)]
final class VerifyApiConnectivityCommandTest extends TestCase
{
    private MockInterface&ReviewsIoClientInterface $reviewsIoClient;

    private MockInterface&MixpanelClientInterface $mixpanelClient;

    private MockInterface&GoogleAdsClientInterface $googleAdsClient;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewsIoClient = Mockery::mock(ReviewsIoClientInterface::class);
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);
        $this->googleAdsClient = Mockery::mock(GoogleAdsClientInterface::class);

        $this->app->instance(ReviewsIoClientInterface::class, $this->reviewsIoClient);
        $this->app->instance(MixpanelClientInterface::class, $this->mixpanelClient);
        $this->app->instance(GoogleAdsClientInterface::class, $this->googleAdsClient);
    }

    /*
    |--------------------------------------------------------------------------
    | Reviews.io Client Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_reviewsio_successfully(): void
    {
        $this->reviewsIoClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->artisan('verify:api', ['client' => 'reviewsio'])
            ->expectsOutput('Verifying Reviews.io...')
            ->expectsOutput('  Authentication: OK')
            ->expectsOutput('  API Response: Valid')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_reviewsio_failure_with_exception_message(): void
    {
        $this->reviewsIoClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Reviews.io'));

        $this->artisan('verify:api', ['client' => 'reviewsio'])
            ->expectsOutput('Verifying Reviews.io...')
            ->expectsOutputToContain('Failed:')
            ->expectsOutput('  Check: REVIEWSIO_API_KEY and REVIEWSIO_STORE in .env')
            ->expectsOutput('Some API clients failed: reviewsio')
            ->assertExitCode(Command::FAILURE);
    }

    /*
    |--------------------------------------------------------------------------
    | Mixpanel Client Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_mixpanel_successfully(): void
    {
        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->artisan('verify:api', ['client' => 'mixpanel'])
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutput('  Authentication: OK')
            ->expectsOutput('  API Response: Valid')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_mixpanel_failure_with_exception_message(): void
    {
        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Mixpanel'));

        $this->artisan('verify:api', ['client' => 'mixpanel'])
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutputToContain('Failed:')
            ->expectsOutput('  Check: MIXPANEL_* credentials in .env')
            ->expectsOutput('Some API clients failed: mixpanel')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_catches_any_throwable_for_mixpanel(): void
    {
        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new RuntimeException('Connection timeout'));

        $this->artisan('verify:api', ['client' => 'mixpanel'])
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutput('  Failed: Connection timeout')
            ->assertExitCode(Command::FAILURE);
    }

    /*
    |--------------------------------------------------------------------------
    | Google Ads Client Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_googleads_with_zero_campaigns(): void
    {
        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $this->artisan('verify:api', ['client' => 'googleads'])
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutput('  Authentication: OK')
            ->expectsOutput('  API Response: Valid (found 0 campaigns)')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_verifies_googleads_with_multiple_campaigns(): void
    {
        $campaigns = [
            new Campaign(campaignId: 1, campaignName: 'Campaign A', status: 'ENABLED'),
            new Campaign(campaignId: 2, campaignName: 'Campaign B', status: 'ENABLED'),
            new Campaign(campaignId: 3, campaignName: 'Campaign C', status: 'PAUSED'),
        ];

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn($campaigns);

        $this->artisan('verify:api', ['client' => 'googleads'])
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutput('  Authentication: OK')
            ->expectsOutput('  API Response: Valid (found 3 campaigns)')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_googleads_failure_with_exception_message(): void
    {
        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->artisan('verify:api', ['client' => 'googleads'])
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutputToContain('Failed:')
            ->expectsOutput('  Check: Google Ads OAuth credentials and refresh token')
            ->expectsOutput('Some API clients failed: googleads')
            ->assertExitCode(Command::FAILURE);
    }

    /*
    |--------------------------------------------------------------------------
    | All Clients Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_all_clients_successfully(): void
    {
        $this->reviewsIoClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Verifying Reviews.io...')
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_single_failure_when_one_client_fails(): void
    {
        $this->reviewsIoClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Mixpanel'));

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andReturn([]);

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Some API clients failed: mixpanel')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_reports_multiple_failures_when_multiple_clients_fail(): void
    {
        $this->reviewsIoClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Reviews.io'));

        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Some API clients failed: reviewsio, googleads')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_reports_all_failures_when_all_clients_fail(): void
    {
        $this->reviewsIoClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Reviews.io'));

        $this->mixpanelClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Mixpanel'));

        $this->googleAdsClient
            ->shouldReceive('getCampaigns')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Some API clients failed: reviewsio, mixpanel, googleads')
            ->assertExitCode(Command::FAILURE);
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid Client Argument Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_failure_for_unknown_client(): void
    {
        $this->artisan('verify:api', ['client' => 'unknown'])
            ->expectsOutput('Unknown client: unknown')
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, all')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_returns_failure_for_empty_client_name(): void
    {
        $this->artisan('verify:api', ['client' => ''])
            ->expectsOutput('Unknown client: ')
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, all')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_returns_failure_for_case_sensitive_client_name(): void
    {
        // 'ReviewsIo' is not valid, only 'reviewsio' is accepted
        $this->artisan('verify:api', ['client' => 'ReviewsIo'])
            ->expectsOutput('Unknown client: ReviewsIo')
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, all')
            ->assertExitCode(Command::FAILURE);
    }
}
