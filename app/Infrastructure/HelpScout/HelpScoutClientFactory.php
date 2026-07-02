<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConnectivityClientInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\ConversationWriteClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\HelpScout\Clients\ConnectivityClient;
use App\Infrastructure\HelpScout\Clients\ConversationsClient;
use App\Infrastructure\HelpScout\Clients\ConversationWriteClient;
use App\Infrastructure\HelpScout\Clients\MailboxesClient;
use App\Infrastructure\HelpScout\Clients\UsersClient;
use App\Infrastructure\HelpScout\Mappers\CustomerMapper;
use App\Infrastructure\HelpScout\Services\NameFormatterService;
use App\Infrastructure\HelpScout\Services\PhoneFormatterService;
use App\Infrastructure\Support\TransientLogThrottle;
use HelpScout\Api\ApiClient;
use HelpScout\Api\ApiClientFactory as SdkClientFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Config;

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
    private static ?ApiClient $sdkClient = null;
    private static ?HelpScoutConfig $config = null;
    private static ?CustomerMapper $customerMapper = null;

    /**
     * Create the conversations read client.
     */
    public static function createConversationsClient(TransientLogThrottle $logThrottle): ConversationsClientInterface
    {
        return new ConversationsClient(self::getTransport($logThrottle));
    }

    /**
     * Create the conversation write client.
     */
    public static function createConversationWriteClient(): ConversationWriteClientInterface
    {
        return new ConversationWriteClient(
            self::getSdkClient(),
            self::getConfig(),
            self::getCustomerMapper(),
        );
    }

    /**
     * Create the mailboxes client.
     */
    public static function createMailboxesClient(TransientLogThrottle $logThrottle): MailboxesClientInterface
    {
        return new MailboxesClient(self::getTransport($logThrottle));
    }

    /**
     * Create the users/agents client.
     */
    public static function createUsersClient(TransientLogThrottle $logThrottle): AgentsClientInterface
    {
        return new UsersClient(self::getTransport($logThrottle));
    }

    /**
     * Create the connectivity client for health checks.
     */
    public static function createConnectivityClient(TransientLogThrottle $logThrottle): ConnectivityClientInterface
    {
        return new ConnectivityClient(self::getTransport($logThrottle));
    }

    /**
     * Get the shared HTTP transport (lazy singleton).
     */
    private static function getTransport(TransientLogThrottle $logThrottle): HelpScoutHttpTransport
    {
        return self::$transport ??= new HelpScoutHttpTransport(
            self::getConfig(),
            self::getSdkClient(),
            \app(HttpFactory::class),
            new HelpScoutErrorHandler($logThrottle),
        );
    }

    /**
     * Get the shared SDK client (lazy singleton).
     */
    private static function getSdkClient(): ApiClient
    {
        return self::$sdkClient ??= self::createSdkClient();
    }

    /**
     * Get the shared config (lazy singleton).
     */
    private static function getConfig(): HelpScoutConfig
    {
        return self::$config ??= self::createConfig();
    }

    /**
     * Get the shared customer mapper (lazy singleton).
     */
    private static function getCustomerMapper(): CustomerMapper
    {
        return self::$customerMapper ??= new CustomerMapper(
            new NameFormatterService(),
            new PhoneFormatterService(),
        );
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
     */
    private static function createConfig(): HelpScoutConfig
    {
        return new HelpScoutConfig(
            mailboxes: self::validateMailboxes(),
            timeoutSeconds: Config::integer('helpscout.timeout_seconds', 30),
            retryAttempts: Config::integer('helpscout.retry_attempts', 3),
        );
    }

    /**
     * Validate and type-check mailbox config from Laravel config source.
     *
     * @return array<string, int>
     *
     * @throws InvalidConfigurationException When config is missing or invalid
     */
    private static function validateMailboxes(): array
    {
        $mailboxes = \config('helpscout.mailboxes');
        if (!\is_array($mailboxes) || ($mailboxes === [])) {
            throw new InvalidConfigurationException('helpscout.mailboxes');
        }

        /** @var array<string, int> $validated */
        $validated = [];
        foreach ($mailboxes as $name => $id) {
            if (!\is_string($name) || !\is_int($id)) {
                throw new InvalidConfigurationException(
                    'helpscout.mailboxes',
                    \sprintf('Invalid mailbox config: expected string => int, got %s => %s', \gettype($name), \gettype($id)),
                );
            }
            $validated[$name] = $id;
        }

        return $validated;
    }

    /**
     * Reset the factory state (for testing only).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$transport = null;
        self::$sdkClient = null;
        self::$config = null;
        self::$customerMapper = null;
    }
}
