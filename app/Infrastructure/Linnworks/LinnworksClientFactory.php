<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Infrastructure\Linnworks\Clients\InventoryClient;
use Illuminate\Cache\CacheManager;
use RuntimeException;

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
     */
    private static function getSessionManager(): LinnworksSessionManager
    {
        return self::$sessionManager ??= new LinnworksSessionManager(
            self::getConfig(),
            \app(CacheManager::class),
        );
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
            throw new RuntimeException('LINNWORKS_APPLICATION_ID not configured');
        }

        if (!\is_string($applicationSecret) || ($applicationSecret === '')) {
            throw new RuntimeException('LINNWORKS_APPLICATION_SECRET not configured');
        }

        if (!\is_string($installationToken) || ($installationToken === '')) {
            throw new RuntimeException('LINNWORKS_INSTALLATION_TOKEN not configured');
        }

        return new LinnworksConfig(
            applicationId: $applicationId,
            applicationSecret: $applicationSecret,
            installationToken: $installationToken,
            timeout: self::getIntConfig('timeout', 30),
            cacheTtlBuffer: self::getIntConfig('cache_ttl_buffer', 300),
        );
    }

    /**
     * Get integer config value with fallback.
     */
    private static function getIntConfig(string $key, int $default): int
    {
        $value = \config("linnworks.{$key}");

        return \is_int($value) ? $value : $default;
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
