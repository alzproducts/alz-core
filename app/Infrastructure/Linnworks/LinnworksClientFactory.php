<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Linnworks\Clients\ConnectivityClient;
use App\Infrastructure\Linnworks\Clients\InventoryClient;
use App\Infrastructure\Linnworks\Clients\InventoryUpdateClient;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Enums\LinnworksLogLevel;
use DateMalformedStringException;
use Illuminate\Support\Facades\Config;

/**
 * Factory for creating Linnworks API clients.
 *
 * Centralizes configuration validation and dependency wiring.
 * The factory reads from Laravel config and constructs the shared
 * transport and session manager that all endpoint clients depend on.
 *
 * Architecture: All endpoint clients share a single LinnworksHttpTransport
 * instance that handles session-based authentication and 401 retry.
 *
 * @template-pattern API Client Factory
 */
final class LinnworksClientFactory
{
    private static ?LinnworksConfig $config = null;

    private static ?LinnworksTransportInterface $transport = null;

    private static ?LinnworksSessionManager $sessionManager = null;

    /**
     * Create the connectivity client for health checks.
     */
    public static function createConnectivityClient(): ConnectivityClientInterface
    {
        return new ConnectivityClient(self::getSessionManager());
    }

    /**
     * Create the inventory client for stock item operations.
     */
    public static function createInventoryClient(): InventoryClientInterface
    {
        return new InventoryClient(self::getTransport());
    }

    /**
     * Create the inventory update client for modifying stock items.
     */
    public static function createInventoryUpdateClient(): InventoryUpdateClientInterface
    {
        return new InventoryUpdateClient(
            self::getTransport(),
            self::createInventoryClient(),
        );
    }

    /**
     * Get the authentication token for manual API calls.
     *
     * Convenience method for development/debugging - retrieves a valid
     * session and returns the bearer token string for use in API requests.
     *
     * Usage in Tinker:
     *   $token = \App\Infrastructure\Linnworks\LinnworksClientFactory::getAuthToken();
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When auth endpoint unavailable
     * @throws DateMalformedStringException When date parsing fails
     */
    public static function getAuthToken(): string
    {
        return self::getSessionManager()->getSession()->token;
    }

    /**
     * Get the shared HTTP transport (lazy singleton).
     *
     * Conditionally wraps transport with logging decorator based on config.
     */
    private static function getTransport(): LinnworksTransportInterface
    {
        return self::$transport ??= self::createTransport();
    }

    /**
     * Get the shared config (lazy singleton).
     */
    private static function getConfig(): LinnworksConfig
    {
        return self::$config ??= self::createConfig();
    }

    /**
     * Get the shared session manager (lazy singleton).
     *
     * Resolves from container to get contextual LockableCacheInterface binding.
     */
    private static function getSessionManager(): LinnworksSessionManager
    {
        return self::$sessionManager ??= \app(LinnworksSessionManager::class);
    }

    /**
     * Create the HTTP transport with optional logging decorator.
     *
     * When LINNWORKS_LOG_LEVEL is set, wraps the base transport with a
     * logging decorator for debugging. Otherwise returns base transport
     * directly (zero overhead in production).
     *
     * @throws InvalidConfigurationException When log level value is invalid
     */
    private static function createTransport(): LinnworksTransportInterface
    {
        $baseTransport = new LinnworksHttpTransport(self::getConfig(), self::getSessionManager());

        $logLevel = \config('linnworks.log_level');

        if (!\is_string($logLevel) || $logLevel === '') {
            return $baseTransport;
        }

        $parsedLogLevel = LinnworksLogLevel::tryFrom($logLevel);

        if ($parsedLogLevel === null) {
            throw new InvalidConfigurationException(
                'LINNWORKS_LOG_LEVEL',
                "Invalid value '{$logLevel}'. Must be 'info' or 'debug'.",
            );
        }

        return new LoggingLinnworksTransport($baseTransport, $parsedLogLevel);
    }

    /**
     * Create validated configuration from Laravel config.
     */
    private static function createConfig(): LinnworksConfig
    {
        $applicationId = \config('linnworks.application_id');
        $applicationSecret = \config('linnworks.application_secret');
        $installationToken = \config('linnworks.installation_token');

        if (!\is_string($applicationId) || ($applicationId === '')) {
            throw new InvalidConfigurationException('LINNWORKS_APPLICATION_ID');
        }

        if (!\is_string($applicationSecret) || ($applicationSecret === '')) {
            throw new InvalidConfigurationException('LINNWORKS_APPLICATION_SECRET');
        }

        if (!\is_string($installationToken) || ($installationToken === '')) {
            throw new InvalidConfigurationException('LINNWORKS_INSTALLATION_TOKEN');
        }

        return new LinnworksConfig(
            applicationId: $applicationId,
            applicationSecret: $applicationSecret,
            installationToken: $installationToken,
            timeout: Config::integer('linnworks.timeout', 30),
            cacheTtlBuffer: Config::integer('linnworks.cache_ttl_buffer', 300),
        );
    }

    /**
     * Reset the factory state (for testing only).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$transport = null;
        self::$sessionManager = null;
    }
}
