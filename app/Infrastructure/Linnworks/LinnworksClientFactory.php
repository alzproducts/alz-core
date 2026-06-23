<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\OrderClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Linnworks\Clients\ConnectivityClient;
use App\Infrastructure\Linnworks\Clients\DashboardsClient;
use App\Infrastructure\Linnworks\Clients\InventoryClient;
use App\Infrastructure\Linnworks\Clients\InventoryFieldUpdateClient;
use App\Infrastructure\Linnworks\Clients\InventoryUpdateClient;
use App\Infrastructure\Linnworks\Clients\OrderClient;
use App\Infrastructure\Linnworks\Clients\OrderDashboardsClient;
use App\Infrastructure\Linnworks\Clients\PurchaseDashboardsClient;
use App\Infrastructure\Linnworks\Clients\PurchaseOrderClient;
use App\Infrastructure\Linnworks\Clients\PurchaseOrderUpdateClient;
use App\Infrastructure\Linnworks\Clients\StockDashboardsClient;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Enums\LinnworksLogLevel;
use App\Infrastructure\Support\TransientLogThrottle;
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
     * Create the inventory field update client for type-safe field updates.
     */
    public static function createInventoryFieldUpdateClient(): InventoryFieldUpdateClientInterface
    {
        return new InventoryFieldUpdateClient(
            self::getTransport(),
            self::createInventoryClient(),
        );
    }

    /**
     * Create the low-level dashboards client for SQL queries.
     *
     * @internal Use createStockDashboardsClient() for stock-related queries.
     */
    public static function createDashboardsClient(): DashboardsClient
    {
        return new DashboardsClient(self::getTransport());
    }

    /**
     * Create the order client for processed order operations.
     */
    public static function createOrderClient(): OrderClientInterface
    {
        return new OrderClient(self::getTransport());
    }

    /**
     * Create the order dashboards client for order-related SQL queries.
     */
    public static function createOrderDashboardsClient(): OrderDashboardsClient
    {
        return new OrderDashboardsClient(self::createDashboardsClient());
    }

    /**
     * Create the purchase order read client.
     */
    public static function createPurchaseOrderClient(): PurchaseOrderClientInterface
    {
        return new PurchaseOrderClient(self::getTransport());
    }

    /**
     * Create the purchase order write client.
     */
    public static function createPurchaseOrderUpdateClient(): PurchaseOrderUpdateClientInterface
    {
        return new PurchaseOrderUpdateClient(self::getTransport());
    }

    /**
     * Create the purchase dashboards client for purchase-order-related SQL queries.
     */
    public static function createPurchaseDashboardsClient(): PurchaseDashboardsClient
    {
        return new PurchaseDashboardsClient(self::createDashboardsClient());
    }

    /**
     * Create the stock dashboards client for stock-related SQL queries.
     */
    public static function createStockDashboardsClient(): StockDashboardsClient
    {
        return new StockDashboardsClient(self::createDashboardsClient());
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
        $baseTransport = self::createBaseTransport();
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

    private static function createBaseTransport(): LinnworksHttpTransport
    {
        return new LinnworksHttpTransport(
            self::getConfig(),
            self::getSessionManager(),
            new LinnworksErrorHandler(\app(TransientLogThrottle::class)),
        );
    }

    /**
     * Create validated configuration from Laravel config.
     */
    private static function createConfig(): LinnworksConfig
    {
        return new LinnworksConfig(
            applicationId: self::requireStringConfig('linnworks.application_id', 'LINNWORKS_APPLICATION_ID'),
            applicationSecret: self::requireStringConfig('linnworks.application_secret', 'LINNWORKS_APPLICATION_SECRET'),
            installationToken: self::requireStringConfig('linnworks.installation_token', 'LINNWORKS_INSTALLATION_TOKEN'),
            timeout: Config::integer('linnworks.timeout', 30),
            cacheTtlBuffer: Config::integer('linnworks.cache_ttl_buffer', 300),
        );
    }

    /**
     * @throws InvalidConfigurationException When the config value is missing or not a non-empty string.
     */
    private static function requireStringConfig(string $configKey, string $envVar): string
    {
        $value = \config($configKey);

        if (!\is_string($value) || ($value === '')) {
            throw new InvalidConfigurationException($envVar);
        }

        return $value;
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
