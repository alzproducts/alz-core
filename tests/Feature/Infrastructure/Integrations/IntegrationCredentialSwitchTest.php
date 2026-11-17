<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Integrations;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\HelpScout\ContactConversationLookupInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Application\HelpScout\Config\HelpScoutSystemUserId;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\BingAds\BingAdsConversionAdapter;
use App\Infrastructure\Conversion\StandIns\StandInAdPlatformConversionAdapter;
use App\Infrastructure\GoogleAds\GoogleAdsConversionAdapter;
use App\Infrastructure\HelpScout\Clients\ConversationWriteClient;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use App\Infrastructure\HelpScout\StandIns\StandInContactConversationLookup;
use App\Infrastructure\HelpScout\StandIns\StandInConversationWriteClient;
use App\Infrastructure\Integrations\IntegrationCredentials;
use App\Infrastructure\Notifications\SlackChatNotificationClient;
use App\Infrastructure\Notifications\StandIns\StandInChatNotification;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Credential presence alone switches HelpScout, Slack, Google Ads and Bing between their real
 * clients and stand-ins; production never binds a stand-in (ADR-0015).
 *
 * intentional: never resolves BingAdsClientInterface — building it sends a live SOAP GetAccount.
 */
#[CoversClass(IntegrationCredentials::class)]
final class IntegrationCredentialSwitchTest extends TestCase
{
    /**
     * Identifiers the real clients need besides the secrets; never part of the switch.
     */
    private const array DUMMY_IDENTIFIERS = [
        'helpscout.auth.type' => 'client_credentials',
        'helpscout.auth.appId' => 'dummy-app-id',
        'helpscout.mailboxes.support' => 1,
        'helpscout.mailboxes.purchase_orders' => 2,
        'helpscout.mailboxes.suppliers_purchasing' => 3,
        'helpscout.mailboxes.accounts' => 4,
        'services.slack.notifications.channel' => '#dummy',
        'google-ads.client_id' => 'dummy-client-id',
        'google-ads.customer_id' => '1234567890',
        'google-ads.login_customer_id' => '1234567890',
        'google-ads.lead_conversion_action_id' => '111',
        'google-ads.quote_conversion_action_id' => '222',
        'bing-ads.client_id' => 'dummy-client-id',
        'bing-ads.account_id' => '123',
        'bing-ads.customer_id' => '456',
        'bing-ads.offline_lead_conversion_goal_name' => 'Dummy goal',
    ];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        HelpScoutClientFactory::reset();
        \config(self::DUMMY_IDENTIFIERS);
    }

    #[Override]
    protected function tearDown(): void
    {
        HelpScoutClientFactory::reset();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'local' => ['local'];
        yield 'production' => ['production'];
    }

    #[Test]
    #[DataProvider('environments')]
    public function it_binds_the_real_clients_when_every_secret_is_set(string $environment): void
    {
        $this->setEnvironment($environment);
        $this->setSecrets(IntegrationCredentials::ALL);

        $this->assertInstanceOf(ConversationWriteClient::class, $this->app->make(ConversationWriteClientInterface::class));
        $this->assertInstanceOf(SlackChatNotificationClient::class, $this->app->make(ChatNotificationInterface::class));

        $resolver = $this->app->make(AdPlatformAdapterResolverService::class);
        $this->assertInstanceOf(GoogleAdsConversionAdapter::class, $resolver->adapterFor(AdPlatform::Google));
        $this->assertInstanceOf(BingAdsConversionAdapter::class, $resolver->adapterFor(AdPlatform::Bing));
    }

    #[Test]
    public function it_binds_stand_ins_outside_production_when_the_secrets_are_blank(): void
    {
        $this->setEnvironment('local');

        $this->assertInstanceOf(StandInConversationWriteClient::class, $this->app->make(ConversationWriteClientInterface::class));
        $this->assertInstanceOf(StandInContactConversationLookup::class, $this->app->make(ContactConversationLookupInterface::class));
        $this->assertSame(1, $this->app->make(HelpScoutSystemUserId::class)->value->value);
        $this->assertInstanceOf(StandInChatNotification::class, $this->app->make(ChatNotificationInterface::class));

        $resolver = $this->app->make(AdPlatformAdapterResolverService::class);
        $this->assertInstanceOf(StandInAdPlatformConversionAdapter::class, $resolver->adapterFor(AdPlatform::Google));
        $this->assertInstanceOf(StandInAdPlatformConversionAdapter::class, $resolver->adapterFor(AdPlatform::Bing));
    }

    #[Test]
    public function it_switches_each_ad_platform_on_its_own_secrets(): void
    {
        $this->setEnvironment('local');
        $this->setSecrets(IntegrationCredentials::GOOGLE_ADS);

        $resolver = $this->app->make(AdPlatformAdapterResolverService::class);

        $this->assertInstanceOf(GoogleAdsConversionAdapter::class, $resolver->adapterFor(AdPlatform::Google));
        $this->assertInstanceOf(StandInAdPlatformConversionAdapter::class, $resolver->adapterFor(AdPlatform::Bing));
    }

    /**
     * @return iterable<string, array{class-string, array<string, string>, string}>
     */
    public static function switchedBindingsWithABlankSecret(): iterable
    {
        yield 'HelpScout write client' => [ConversationWriteClientInterface::class, IntegrationCredentials::HELPSCOUT, 'helpscout.auth.appSecret'];
        yield 'HelpScout lookup' => [ContactConversationLookupInterface::class, IntegrationCredentials::HELPSCOUT, 'helpscout.auth.appSecret'];
        yield 'HelpScout system user id' => [HelpScoutSystemUserId::class, IntegrationCredentials::HELPSCOUT, 'helpscout.auth.appSecret'];
        yield 'Slack' => [ChatNotificationInterface::class, IntegrationCredentials::SLACK, 'services.slack.notifications.bot_user_oauth_token'];
        yield 'Google Ads' => [AdPlatformAdapterResolverService::class, IntegrationCredentials::GOOGLE_ADS, 'google-ads.refresh_token'];
        yield 'Bing' => [AdPlatformAdapterResolverService::class, IntegrationCredentials::BING_ADS, 'bing-ads.developer_token'];
    }

    /**
     * @param class-string $abstract
     * @param array<string, string> $integrationSecrets
     */
    #[Test]
    #[DataProvider('switchedBindingsWithABlankSecret')]
    public function production_refuses_a_stand_in_and_names_the_blank_key(string $abstract, array $integrationSecrets, string $blankKey): void
    {
        $this->setEnvironment('production');
        $this->setSecrets(IntegrationCredentials::ALL);
        \config([$blankKey => '']);

        try {
            $this->app->make($abstract);
            $this->fail("Production resolved {$abstract} with {$blankKey} blank.");
        } catch (InvalidConfigurationException $e) {
            $this->assertSame($blankKey, $e->configKey);
            $this->assertStringContainsString($integrationSecrets[$blankKey], $e->detail);
        }
    }

    private function setEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(static fn(): string => $environment);
    }

    /**
     * @param array<string, string> $credentials
     */
    private function setSecrets(array $credentials): void
    {
        \config(\array_map(static fn(): string => 'dummy-secret', $credentials));
    }
}
