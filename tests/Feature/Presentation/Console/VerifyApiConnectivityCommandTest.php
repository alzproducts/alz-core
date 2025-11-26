<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\ShopwiredClientInterface;
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

    private MockInterface&ShopwiredClientInterface $shopwiredClient;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewsIoClient = Mockery::mock(ReviewsIoClientInterface::class);
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);
        $this->googleAdsClient = Mockery::mock(GoogleAdsClientInterface::class);
        $this->shopwiredClient = Mockery::mock(ShopwiredClientInterface::class);

        $this->app->instance(ReviewsIoClientInterface::class, $this->reviewsIoClient);
        $this->app->instance(MixpanelClientInterface::class, $this->mixpanelClient);
        $this->app->instance(GoogleAdsClientInterface::class, $this->googleAdsClient);
        $this->app->instance(ShopwiredClientInterface::class, $this->shopwiredClient);
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
    public function it_verifies_googleads_successfully(): void
    {
        $this->googleAdsClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->artisan('verify:api', ['client' => 'googleads'])
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutput('  Authentication: OK')
            ->expectsOutput('  API Response: Valid')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_googleads_failure_with_exception_message(): void
    {
        $this->googleAdsClient
            ->shouldReceive('verifyConnectivity')
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
    | Shopwired Client Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_verifies_shopwired_successfully(): void
    {
        $this->shopwiredClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->artisan('verify:api', ['client' => 'shopwired'])
            ->expectsOutput('Verifying Shopwired...')
            ->expectsOutput('  Authentication: OK')
            ->expectsOutput('  API Response: Valid')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_shopwired_failure_with_exception_message(): void
    {
        $this->shopwiredClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Shopwired'));

        $this->artisan('verify:api', ['client' => 'shopwired'])
            ->expectsOutput('Verifying Shopwired...')
            ->expectsOutputToContain('Failed:')
            ->expectsOutput('  Check: SHOPWIRED_API_KEY and SHOPWIRED_API_SECRET in .env')
            ->expectsOutput('Some API clients failed: shopwired')
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
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->shopwiredClient
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Verifying Reviews.io...')
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutput('Verifying Shopwired...')
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
            ->shouldReceive('verifyConnectivity')
            ->once();

        $this->shopwiredClient
            ->shouldReceive('verifyConnectivity')
            ->once();

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
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->shopwiredClient
            ->shouldReceive('verifyConnectivity')
            ->once();

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
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Google Ads'));

        $this->shopwiredClient
            ->shouldReceive('verifyConnectivity')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Shopwired'));

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Some API clients failed: reviewsio, mixpanel, googleads, shopwired')
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
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, shopwired, all')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_returns_failure_for_empty_client_name(): void
    {
        $this->artisan('verify:api', ['client' => ''])
            ->expectsOutput('Unknown client: ')
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, shopwired, all')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_returns_failure_for_case_sensitive_client_name(): void
    {
        // 'ReviewsIo' is not valid, only 'reviewsio' is accepted
        $this->artisan('verify:api', ['client' => 'ReviewsIo'])
            ->expectsOutput('Unknown client: ReviewsIo')
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, shopwired, all')
            ->assertExitCode(Command::FAILURE);
    }
}
