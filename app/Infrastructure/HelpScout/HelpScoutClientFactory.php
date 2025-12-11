<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Infrastructure\HelpScout\Clients\ConversationsClient;
use App\Infrastructure\HelpScout\Clients\MailboxesClient;
use App\Infrastructure\HelpScout\Clients\UsersClient;
use HelpScout\Api\ApiClient;
use RuntimeException;

/**
 * Factory for creating HelpScout API clients with all dependencies.
 *
 * Centralizes configuration validation and dependency wiring.
 * The factory reads from Laravel config and constructs the full
 * dependency chain: Config → Transport → Client.
 *
 * @template-pattern API Client Factory
 */
final class HelpScoutClientFactory
{
    /**
     * Create the shared HTTP transport with validated config.
     *
     * The transport handles authentication via the SDK's OAuth2 client
     * and provides the HTTP layer for all endpoint clients.
     */
    public static function createTransport(ApiClient $sdkClient): HelpScoutHttpTransport
    {
        $config = self::createConfig();

        return new HelpScoutHttpTransport($config, $sdkClient);
    }

    /**
     * Create configured HelpScoutConfig from Laravel config.
     *
     * @throws RuntimeException When mailboxes configuration is missing or invalid
     */
    public static function createConfig(): HelpScoutConfig
    {
        $mailboxes = \config('helpscout.mailboxes');

        if (!\is_array($mailboxes) || $mailboxes === []) {
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
            localTestEmail: self::getNullableString('helpscout.local_test_email'),
            timeoutSeconds: self::getIntConfig('helpscout.timeout_seconds', 30),
            retryAttempts: self::getIntConfig('helpscout.retry_attempts', 3),
        );
    }

    /**
     * Create ConversationsClient with the given transport.
     */
    public static function createConversationsClient(HelpScoutHttpTransport $transport): ConversationsClient
    {
        return new ConversationsClient($transport);
    }

    /**
     * Create MailboxesClient with the given transport.
     */
    public static function createMailboxesClient(HelpScoutHttpTransport $transport): MailboxesClient
    {
        return new MailboxesClient($transport);
    }

    /**
     * Create UsersClient with the given transport.
     */
    public static function createUsersClient(HelpScoutHttpTransport $transport): UsersClient
    {
        return new UsersClient($transport);
    }

    /**
     * Get nullable string config value.
     */
    private static function getNullableString(string $key): ?string
    {
        $value = \config($key);

        if ($value === null || $value === '') {
            return null;
        }

        return \is_string($value) ? $value : null;
    }

    /**
     * Get integer config value with fallback.
     */
    private static function getIntConfig(string $key, int $default): int
    {
        $value = \config($key);

        return \is_int($value) ? $value : $default;
    }
}
