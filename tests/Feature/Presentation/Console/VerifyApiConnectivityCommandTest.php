<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console;

use App\Application\Contracts\BingAdsClientInterface;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\HelpScout\ConnectivityClientInterface as HelpScoutConnectivityClient;
use App\Application\Contracts\Linnworks\ConnectivityClientInterface as LinnworksConnectivityClient;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface as ShopwiredConnectivityClient;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Presentation\Console\Commands\VerifyApiConnectivityCommand;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    use DatabaseTransactions;

    private MockInterface&ReviewsIoClientInterface $reviewsIoClient;

    private MockInterface&MixpanelClientInterface $mixpanelClient;

    private MockInterface&GoogleAdsClientInterface $googleAdsClient;

    private MockInterface&BingAdsClientInterface $bingAdsClient;

    private MockInterface&ShopwiredConnectivityClient $shopwiredClient;

    private MockInterface&LinnworksConnectivityClient $linnworksClient;

    private MockInterface&HelpScoutConnectivityClient $helpscoutClient;

    private MockInterface&QuickBooksClientInterface $quickBooksClient;

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

        $this->quickBooksClient = Mockery::mock(QuickBooksClientInterface::class);
        $this->app->instance(QuickBooksClientInterface::class, $this->quickBooksClient);
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-stored'),
            refreshTokenExpiresAt: new DateTimeImmutable('+30 days'),
            refreshTokenHardExpiresAt: null,
        ));
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)
            ->put(new ApiKeyToken('access-cached'), new DateTimeImmutable('+1 hour'));
    }

    #[Test]
    public function it_prints_the_connected_quickbooks_company(): void
    {
        $this->quickBooksClient->shouldReceive('companyName')->once()->andReturn('Sandbox Company_US_1');

        $this->artisan('verify:api', ['client' => 'quickbooks'])
            ->expectsOutput('Verifying QuickBooks...')
            ->expectsOutput('  Company: Sandbox Company_US_1')
            ->expectsOutput('All API clients verified successfully')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function an_invalid_grant_fails_quickbooks_with_the_reconnect_hint(): void
    {
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)->forget();
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('refresh-revoked'),
            refreshTokenExpiresAt: new DateTimeImmutable('+30 days'),
            refreshTokenHardExpiresAt: null,
        ));
        Http::preventStrayRequests();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->quickBooksClient->shouldNotReceive('companyName');

        $this->artisan('verify:api', ['client' => 'quickbooks'])
            ->expectsOutput('Verifying QuickBooks...')
            ->expectsOutputToContain('Authorization Failed:')
            ->expectsOutput('  Check: reconnect with php artisan quickbooks:connect')
            ->expectsOutput('Some API clients failed: quickbooks')
            ->assertExitCode(Command::FAILURE);
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
        $this->quickBooksClient->shouldReceive('companyName')->once()->andReturn('Sandbox Company_US_1');

        $this->artisan('verify:api', ['client' => 'all'])
            ->expectsOutput('Verifying Reviews.io...')
            ->expectsOutput('Verifying Mixpanel...')
            ->expectsOutput('Verifying Google Ads...')
            ->expectsOutput('Verifying Bing Ads...')
            ->expectsOutput('Verifying Shopwired...')
            ->expectsOutput('Verifying Linnworks...')
            ->expectsOutput('Verifying HelpScout...')
            ->expectsOutput('Verifying QuickBooks...')
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
            ->expectsOutput('Available: reviewsio, mixpanel, googleads, bingads, shopwired, linnworks, helpscout, quickbooks, all')
            ->assertExitCode(Command::FAILURE);
    }
}
