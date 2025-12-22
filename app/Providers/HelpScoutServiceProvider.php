<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use App\Application\Support\EmailAliasResolver;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;

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
        $this->app->singleton(
            ConversationsClientInterface::class,
            static fn(): ConversationsClientInterface => HelpScoutClientFactory::createConversationsClient(),
        );

        $this->app->singleton(
            MailboxesClientInterface::class,
            static fn(): MailboxesClientInterface => HelpScoutClientFactory::createMailboxesClient(),
        );

        $this->app->singleton(
            AgentsClientInterface::class,
            static fn(): AgentsClientInterface => HelpScoutClientFactory::createUsersClient(),
        );

        $this->app->bind(
            GetEscalationsUseCase::class,
            static fn(Application $app): GetEscalationsUseCase => new GetEscalationsUseCase(
                $app->make(CachingHelpScoutService::class),
                Config::integer('helpscout.mailboxes.support'),
                Config::integer('helpscout.mailboxes.purchase_orders'),
            ),
        );

        // Contextual binding: CachingHelpScoutService gets HelpScout-specific email aliases
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
            MailboxesClientInterface::class,
            AgentsClientInterface::class,
            GetEscalationsUseCase::class,
        ];
    }
}
