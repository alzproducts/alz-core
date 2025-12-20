<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Linnworks\Clients\ConnectivityClient;
use App\Infrastructure\Linnworks\Clients\InventoryClient;
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

    private static ?LinnworksHttpTransport $transport = null;

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
     * Get the shared HTTP transport (lazy singleton).
     */
    private static function getTransport(): LinnworksHttpTransport
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
     * Create the HTTP transport with session manager.
     */
    private static function createTransport(): LinnworksHttpTransport
    {
        return new LinnworksHttpTransport(self::getConfig(), self::getSessionManager());
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
