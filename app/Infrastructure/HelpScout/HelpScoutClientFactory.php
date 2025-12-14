<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Infrastructure\HelpScout\Clients\ConversationsClient;
use App\Infrastructure\HelpScout\Clients\MailboxesClient;
use App\Infrastructure\HelpScout\Clients\UsersClient;
use HelpScout\Api\ApiClient;
use HelpScout\Api\ApiClientFactory as SdkClientFactory;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Factory for creating HelpScout API clients with all dependencies.
 *
 * Centralizes configuration validation and dependency wiring.
 * The factory reads from Laravel config and constructs the full
 * dependency chain: Config → SDK Client → Transport → Client.
 *
 * Architecture: All endpoint clients share a single HelpScoutHttpTransport
 * instance that handles authentication, retry logic, and timeout.
 *
 * @template-pattern API Client Factory
 */
final class HelpScoutClientFactory
{
    private static ?HelpScoutHttpTransport $transport = null;

    /**
     * Create the conversations client.
     */
    public static function createConversationsClient(ConcurrencyDriver $concurrency): ConversationsClientInterface
    {
        return new ConversationsClient(self::getTransport(), $concurrency);
    }

    /**
     * Create the mailboxes client.
     */
    public static function createMailboxesClient(): MailboxesClientInterface
    {
        return new MailboxesClient(self::getTransport());
    }

    /**
     * Create the users/agents client.
     */
    public static function createUsersClient(): AgentsClientInterface
    {
        return new UsersClient(self::getTransport());
    }

    /**
     * Get the shared HTTP transport (lazy singleton).
     *
     * Creates the transport on first access, reuses for subsequent calls.
     * This ensures all clients share the same transport instance.
     */
    private static function getTransport(): HelpScoutHttpTransport
    {
        return self::$transport ??= self::createTransport();
    }

    /**
     * Create the HTTP transport with validated configuration.
     */
    private static function createTransport(): HelpScoutHttpTransport
    {
        $config = self::createConfig();
        $sdkClient = self::createSdkClient();

        return new HelpScoutHttpTransport($config, $sdkClient);
    }

    /**
     * Create the HelpScout SDK client for OAuth2 authentication.
     *
     * We use the SDK solely for token management; actual API calls
     * go through direct HTTP for full field support.
     */
    private static function createSdkClient(): ApiClient
    {
        /** @var array<string, mixed> $authConfig */
        $authConfig = \config('helpscout.auth', []);

        // SDK expects config wrapped in 'auth' key
        return SdkClientFactory::createClient(['auth' => $authConfig]);
    }

    /**
     * Create configured HelpScoutConfig from Laravel config.
     *
     * @throws RuntimeException When mailboxes configuration is missing or invalid
     */
    private static function createConfig(): HelpScoutConfig
    {
        $mailboxes = \config('helpscout.mailboxes');

        if (!\is_array($mailboxes) || ($mailboxes === [])) {
            throw new RuntimeException('helpscout.mailboxes not configured');
        }

        /** @var array<string, int> $validatedMailboxes */
        $validatedMailboxes = [];

        foreach ($mailboxes as $name => $id) {
            if (!\is_string($name) || !\is_int($id)) {
                throw new RuntimeException(
                    \sprintf('Invalid mailbox config: expected string => int, got %s => %s', \gettype($name), \gettype($id)),
                );
            }
            $validatedMailboxes[$name] = $id;
        }

        return new HelpScoutConfig(
            mailboxes: $validatedMailboxes,
            timeoutSeconds: Config::integer('helpscout.timeout_seconds', 30),
            retryAttempts: Config::integer('helpscout.retry_attempts', 3),
        );
    }

    /**
     * Reset the factory state (for testing only).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$transport = null;
    }
}
