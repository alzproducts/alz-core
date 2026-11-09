<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use App\Application\Contracts\BingAdsClientInterface;
use App\Application\Contracts\ClickUp\TasksClientInterface;
use App\Application\Contracts\ClickUp\UsersClientInterface;
use App\Application\Contracts\Conversion\GoogleAdsConversionProbeInterface;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\Linnworks\ConnectivityClientInterface as LinnworksConnectivityClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\BingAds\Fakes\FailClosedBingAdsClient;
use App\Infrastructure\ClickUp\ClickUpHttpTransport;
use App\Infrastructure\ClickUp\Fakes\FailClosedTasksClient;
use App\Infrastructure\ClickUp\Fakes\FailClosedUsersClient;
use App\Infrastructure\Feeds\Fakes\FailClosedProductSearchFeedProcessor;
use App\Infrastructure\GoogleAds\Fakes\FailClosedGoogleAdsClient;
use App\Infrastructure\GoogleAds\Fakes\FailClosedGoogleAdsConversionProbe;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use App\Infrastructure\Linnworks\Fakes\FailClosedConnectivityClient;
use App\Infrastructure\Linnworks\LinnworksClientFactory;
use App\Infrastructure\Mixpanel\Fakes\FailClosedMixpanelClient;
use App\Infrastructure\ReviewsIo\Fakes\FailClosedReviewsIoClient;
use App\Infrastructure\Shopwired\ShopwiredClientFactory;
use App\Infrastructure\Support\TransientLogThrottle;
use Closure;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every outbound seam with no recording fake resolves to a stub that throws on use, outside production,
 * with no `*_LIVE` opt-in (phpunit pins them all false).
 */
final class FailClosedBindingsTest extends TestCase
{
    private const array DISK_ENV_KEYS = ['APP_ENV', 'REMOTE_STORAGE_LIVE', 'REMOTE_STORAGE_DISK', 'FEEDS_STORAGE_DISK'];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        ShopwiredClientFactory::reset();
        LinnworksClientFactory::reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        ShopwiredClientFactory::reset();
        LinnworksClientFactory::reset();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{class-string, class-string, LiveIntegration, Closure(object): mixed}>
     */
    public static function boundStubs(): iterable
    {
        yield 'google ads client' => [GoogleAdsClientInterface::class, FailClosedGoogleAdsClient::class, LiveIntegration::GoogleAds, static fn(GoogleAdsClientInterface $c): mixed => $c->verifyConnectivity()];
        yield 'google ads conversion probe' => [GoogleAdsConversionProbeInterface::class, FailClosedGoogleAdsConversionProbe::class, LiveIntegration::GoogleAds, static fn(GoogleAdsConversionProbeInterface $c): mixed => $c->probeUpload()];
        yield 'bing ads client' => [BingAdsClientInterface::class, FailClosedBingAdsClient::class, LiveIntegration::BingAds, static fn(BingAdsClientInterface $c): mixed => $c->verifyConnectivity()];
        yield 'mixpanel client' => [MixpanelClientInterface::class, FailClosedMixpanelClient::class, LiveIntegration::Mixpanel, static fn(MixpanelClientInterface $c): mixed => $c->verifyConnectivity()];
        yield 'reviews.io client' => [ReviewsIoClientInterface::class, FailClosedReviewsIoClient::class, LiveIntegration::ReviewsIo, static fn(ReviewsIoClientInterface $c): mixed => $c->verifyConnectivity()];
        yield 'clickup users client' => [UsersClientInterface::class, FailClosedUsersClient::class, LiveIntegration::ClickUp, static fn(UsersClientInterface $c): mixed => $c->getUser(new ApiKeyToken('pk_probe'))];
        yield 'clickup tasks client' => [TasksClientInterface::class, FailClosedTasksClient::class, LiveIntegration::ClickUp, static fn(TasksClientInterface $c): mixed => $c->getTasksForList(new ApiKeyToken('pk_probe'), 'list', new ClickUpTaskQueryParams())];
        yield 'doofinder feed processor' => [ProductSearchFeedProcessorInterface::class, FailClosedProductSearchFeedProcessor::class, LiveIntegration::Doofinder, static fn(ProductSearchFeedProcessorInterface $c): mixed => $c->process('https://example.test/feed.xml', 'feeds/out.xml')];
        yield 'linnworks connectivity client' => [LinnworksConnectivityClientInterface::class, FailClosedConnectivityClient::class, LiveIntegration::Linnworks, static fn(LinnworksConnectivityClientInterface $c): mixed => $c->verifyConnectivity()];
    }

    /**
     * @param class-string $abstract
     * @param class-string $stub
     * @param Closure(object): mixed $call
     */
    #[Test]
    #[DataProvider('boundStubs')]
    public function binding_resolves_to_a_stub_that_fails_closed(string $abstract, string $stub, LiveIntegration $integration, Closure $call): void
    {
        $client = $this->app->make($abstract);

        $this->assertInstanceOf($stub, $client);
        $this->assertFailsClosed($integration, static fn(): mixed => $call($client));
    }

    #[Test]
    public function shopwired_clients_get_a_fail_closed_transport(): void
    {
        $client = ShopwiredClientFactory::createConnectivityClient($this->app->make(TransientLogThrottle::class));

        $this->assertFailsClosed(LiveIntegration::Shopwired, static fn(): mixed => $client->verifyConnectivity());
    }

    #[Test]
    public function linnworks_clients_get_a_fail_closed_transport(): void
    {
        $client = LinnworksClientFactory::createOrderClient($this->app->make(TransientLogThrottle::class));

        $this->assertFailsClosed(LiveIntegration::Linnworks, static fn(): mixed => $client->getOrderById(Guid::fromTrusted('00000000-0000-0000-0000-000000000001')));
    }

    #[Test]
    public function linnworks_auth_token_fails_closed(): void
    {
        $this->assertFailsClosed(LiveIntegration::Linnworks, static fn(): string => LinnworksClientFactory::getAuthToken());
    }

    #[Test]
    public function clickup_transport_resolved_directly_fails_closed(): void
    {
        $this->assertFailsClosed(LiveIntegration::ClickUp, fn(): mixed => $this->app->make(ClickUpHttpTransport::class));
    }

    #[Test]
    public function clickup_transport_resolves_when_live(): void
    {
        $this->app['env'] = 'production';
        $this->app->forgetInstance(LiveIntegrationGate::class);
        $this->app->forgetInstance(ClickUpHttpTransport::class);

        try {
            $this->assertInstanceOf(ClickUpHttpTransport::class, $this->app->make(ClickUpHttpTransport::class));
        } finally {
            $this->app['env'] = 'testing';
            $this->app->forgetInstance(LiveIntegrationGate::class);
            $this->app->forgetInstance(ClickUpHttpTransport::class);
        }
    }

    #[Test]
    public function slack_test_basic_mode_fails_closed(): void
    {
        $this->assertFailsClosed(
            LiveIntegration::Slack,
            fn(): int => $this->withoutMockingConsoleOutput()->artisan('slack:test', ['channel' => '#probe', 'message' => 'probe']),
        );
    }

    #[Test]
    public function remote_and_feed_disks_resolve_to_local(): void
    {
        $this->assertSame(['local', 'local'], self::disksUnder(['APP_ENV' => 'testing']));
        $this->assertSame(['local', 'local'], self::disksUnder(['APP_ENV' => 'local', 'REMOTE_STORAGE_DISK' => 's3', 'FEEDS_STORAGE_DISK' => 's3']));
    }

    #[Test]
    public function remote_and_feed_disks_honour_the_opt_in(): void
    {
        $this->assertSame(['s3', 's3'], self::disksUnder(['APP_ENV' => 'local', 'REMOTE_STORAGE_LIVE' => 'true']));
    }

    #[Test]
    public function production_is_live_for_every_integration_and_uses_s3(): void
    {
        $this->app['env'] = 'production';
        $this->app->forgetInstance(LiveIntegrationGate::class);

        try {
            $gate = $this->app->make(LiveIntegrationGate::class);

            foreach (LiveIntegration::cases() as $integration) {
                $this->assertTrue($gate->isLive($integration), $integration->name);
            }
        } finally {
            $this->app['env'] = 'testing';
            $this->app->forgetInstance(LiveIntegrationGate::class);
        }

        $this->assertSame(['s3', 's3'], self::disksUnder(['APP_ENV' => 'production']));
    }

    /**
     * @param Closure(): mixed $call
     */
    private function assertFailsClosed(LiveIntegration $integration, Closure $call): void
    {
        try {
            $call();
        } catch (InvalidConfigurationException $e) {
            $this->assertSame($integration->optInSetting(), $e->configKey);

            return;
        }

        $this->fail('Expected ' . $integration->optInSetting() . ' to fail closed.');
    }

    /**
     * Loads both config files fresh under the given env, restoring every touched variable afterwards.
     *
     * @param array<string, string> $env
     * @return array{mixed, mixed} [filesystems.remote, feeds.doofinder.storage_disk]
     */
    private static function disksUnder(array $env): array
    {
        $saved = [];

        foreach (self::DISK_ENV_KEYS as $key) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, \getenv($key)];
            self::setEnv($key, $env[$key] ?? null);
        }

        try {
            /** @var array{remote: mixed} $filesystems */
            $filesystems = require \config_path('filesystems.php');
            /** @var array{doofinder: array{storage_disk: mixed}} $feeds */
            $feeds = require \config_path('feeds.php');

            return [$filesystems['remote'], $feeds['doofinder']['storage_disk']];
        } finally {
            foreach ($saved as $key => [$server, $envValue, $putenv]) {
                self::restoreEnv($key, $server, $envValue, $putenv);
            }
        }
    }

    private static function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            \putenv($key);

            return;
        }

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        \putenv("{$key}={$value}");
    }

    private static function restoreEnv(string $key, mixed $server, mixed $envValue, string|false $putenv): void
    {
        unset($_SERVER[$key], $_ENV[$key]);

        if ($server !== null) {
            $_SERVER[$key] = $server;
        }

        if ($envValue !== null) {
            $_ENV[$key] = $envValue;
        }

        \putenv($putenv === false ? $key : "{$key}={$putenv}");
    }
}
