<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConnectivityClientInterface;
use App\Application\Contracts\HelpScout\ContactConversationLookupInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\HelpScout\Config\HelpScoutSystemUserId;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use App\Application\Support\EmailAliasResolver;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\HelpScout\Fakes\RecordingAgentsClient;
use App\Infrastructure\HelpScout\Fakes\RecordingConnectivityClient;
use App\Infrastructure\HelpScout\Fakes\RecordingContactConversationLookup;
use App\Infrastructure\HelpScout\Fakes\RecordingConversationsClient;
use App\Infrastructure\HelpScout\Fakes\RecordingMailboxesClient;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use App\Infrastructure\Support\TransientLogThrottle;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

/**
 * HelpScout API Client Service Provider.
 *
 * Deferred provider for HelpScout endpoint clients - only loads when requested.
 * Configuration validation is handled by the Factory (fail-fast pattern).
 *
 * Architecture: All endpoint clients share a single HelpScoutHttpTransport
 * instance managed by the factory (lazy singleton pattern).
 *
 * @template-pattern API Client Service Provider
 */
final class HelpScoutServiceProvider extends ServiceProvider implements DeferrableProvider
{
    private const int PLACEHOLDER_SYSTEM_USER_ID = 1;

    /**
     * Register HelpScout API clients.
     *
     * Delegates to HelpScoutClientFactory which handles:
     * - Configuration validation (fail-fast with RuntimeException)
     * - SDK client creation (OAuth2 authentication)
     * - Dependency wiring (Config → SDK → Transport → Client)
     * - Transport singleton management (shared across all clients)
     */
    #[Override]
    public function register(): void
    {
        $this->registerClients();
        $this->registerContactConversationLookup();
        $this->registerSystemUserId();
        $this->registerUseCases();
    }

    private function registerClients(): void
    {
        $this->app->singleton(
            ConversationsClientInterface::class,
            static fn(Application $app): ConversationsClientInterface => self::isLive($app)
                ? HelpScoutClientFactory::createConversationsClient($app->make(TransientLogThrottle::class))
                : $app->make(RecordingConversationsClient::class),
        );
        $this->app->singleton(
            MailboxesClientInterface::class,
            static fn(Application $app): MailboxesClientInterface => self::isLive($app)
                ? HelpScoutClientFactory::createMailboxesClient($app->make(TransientLogThrottle::class))
                : $app->make(RecordingMailboxesClient::class),
        );
        $this->app->singleton(
            AgentsClientInterface::class,
            static fn(Application $app): AgentsClientInterface => self::isLive($app)
                ? HelpScoutClientFactory::createUsersClient($app->make(TransientLogThrottle::class))
                : $app->make(RecordingAgentsClient::class),
        );
        $this->app->singleton(
            ConnectivityClientInterface::class,
            static fn(Application $app): ConnectivityClientInterface => self::isLive($app)
                ? HelpScoutClientFactory::createConnectivityClient($app->make(TransientLogThrottle::class))
                : $app->make(RecordingConnectivityClient::class),
        );
    }

    private function registerContactConversationLookup(): void
    {
        $this->app->singleton(
            ContactConversationLookupInterface::class,
            static fn(Application $app): ContactConversationLookupInterface => self::isLive($app)
                ? HelpScoutClientFactory::createContactConversationLookup($app->make(TransientLogThrottle::class))
                : $app->make(RecordingContactConversationLookup::class),
        );
    }

    private static function isLive(Application $app): bool
    {
        return $app->make(LiveIntegrationGate::class)->isLive(LiveIntegration::HelpScout);
    }

    private function registerSystemUserId(): void
    {
        $this->app->singleton(
            HelpScoutSystemUserId::class,
            static function (Application $app): HelpScoutSystemUserId {
                if (! self::isLive($app)) {
                    return new HelpScoutSystemUserId(IntId::from(self::PLACEHOLDER_SYSTEM_USER_ID));
                }

                $configValue = \config('helpscout.system_user_id');
                $userId = \is_numeric($configValue) ? (int) $configValue : 0;

                if ($userId <= 0) {
                    throw new RuntimeException(
                        'HELPSCOUT_SYSTEM_USER_ID not configured. Get ID from: Help Scout → Manage → Users → Click user → ID in URL',
                    );
                }

                return new HelpScoutSystemUserId(IntId::from($userId));
            },
        );
    }

    private function registerUseCases(): void
    {
        $this->app->bind(
            GetEscalationsUseCase::class,
            static fn(Application $app): GetEscalationsUseCase => new GetEscalationsUseCase(
                $app->make(CachingHelpScoutService::class),
                Config::integer('helpscout.mailboxes.support'),
                Config::integer('helpscout.mailboxes.purchase_orders'),
            ),
        );

        $this->app->when(CachingHelpScoutService::class)
            ->needs(EmailAliasResolver::class)
            ->give(static function (): EmailAliasResolver {
                /** @var array<string, string> $aliases */
                $aliases = Config::array('helpscout.email_aliases', []);

                return new EmailAliasResolver($aliases);
            });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ConversationsClientInterface::class,
            ContactConversationLookupInterface::class,
            MailboxesClientInterface::class,
            AgentsClientInterface::class,
            ConnectivityClientInterface::class,
            HelpScoutSystemUserId::class,
            GetEscalationsUseCase::class,
        ];
    }
}
