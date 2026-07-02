<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomFieldClientInterface;
use App\Application\Contracts\Shopwired\FilterGroupClientInterface;
use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Contracts\Shopwired\WebhookClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Shopwired\Clients\BrandClient;
use App\Infrastructure\Shopwired\Clients\CategoryClient;
use App\Infrastructure\Shopwired\Clients\CustomerClient;
use App\Infrastructure\Shopwired\Clients\CustomFieldClient;
use App\Infrastructure\Shopwired\Clients\FilterGroupClient;
use App\Infrastructure\Shopwired\Clients\OrderClient;
use App\Infrastructure\Shopwired\Clients\PriceUpdateClient;
use App\Infrastructure\Shopwired\Clients\StockClient;
use App\Infrastructure\Shopwired\Clients\WebhookClient;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Enums\ShopwiredLogLevel;
use App\Infrastructure\Support\TransientLogThrottle;
use Illuminate\Support\Facades\Config;

/**
 * Factory for creating ShopWired API clients.
 *
 * Centralizes configuration validation and dependency wiring.
 * The factory reads from Laravel config and constructs the shared
 * transport that all endpoint clients depend on.
 *
 * Architecture: All endpoint clients share a single ShopwiredHttpTransport
 * instance that handles authentication, retry logic, and timeout.
 *
 * @template-pattern API Client Factory
 */
final class ShopwiredClientFactory
{
    private static ?ShopwiredTransportInterface $transport = null;

    /**
     * Create the connectivity client for API health checks.
     */
    public static function createConnectivityClient(TransientLogThrottle $logThrottle): ConnectivityClientInterface
    {
        return new ShopwiredClient(self::getTransport($logThrottle));
    }

    /**
     * Create the brand client for brand operations.
     */
    public static function createBrandClient(TransientLogThrottle $logThrottle): BrandClientInterface
    {
        return new BrandClient(self::getTransport($logThrottle));
    }

    /**
     * Create the category client for category operations.
     */
    public static function createCategoryClient(TransientLogThrottle $logThrottle): CategoryClientInterface
    {
        return new CategoryClient(self::getTransport($logThrottle));
    }

    /**
     * Create the custom field client for custom field definitions.
     */
    public static function createCustomFieldClient(TransientLogThrottle $logThrottle): CustomFieldClientInterface
    {
        return new CustomFieldClient(self::getTransport($logThrottle));
    }

    /**
     * Create the filter group client for filter group definitions.
     */
    public static function createFilterGroupClient(TransientLogThrottle $logThrottle): FilterGroupClientInterface
    {
        return new FilterGroupClient(self::getTransport($logThrottle));
    }

    /**
     * Create the customer client for customer operations.
     */
    public static function createCustomerClient(TransientLogThrottle $logThrottle): CustomerClientInterface
    {
        return new CustomerClient(self::getTransport($logThrottle));
    }

    /**
     * Create the order client for order operations.
     */
    public static function createOrderClient(TransientLogThrottle $logThrottle): OrderClientInterface
    {
        return new OrderClient(self::getTransport($logThrottle));
    }

    /**
     * Create the stock client for stock quantity updates.
     */
    public static function createStockClient(TransientLogThrottle $logThrottle): StockClientInterface
    {
        return new StockClient(self::getTransport($logThrottle));
    }

    /**
     * Create the price update client for batch price updates.
     */
    public static function createPriceUpdateClient(TransientLogThrottle $logThrottle): PriceUpdateClientInterface
    {
        return new PriceUpdateClient(self::getTransport($logThrottle));
    }

    /**
     * Create the webhook client for webhook health monitoring.
     */
    public static function createWebhookClient(TransientLogThrottle $logThrottle): WebhookClientInterface
    {
        return new WebhookClient(self::getTransport($logThrottle));
    }

    /**
     * Get the shared HTTP transport (lazy singleton).
     *
     * Creates the transport on first access, reuses for subsequent calls.
     * This ensures all clients share the same transport instance.
     *
     * Note: Made public for ServiceProvider to wire ProductClient with its
     * scoped dependencies (ProductDomainFactory).
     */
    public static function getTransport(TransientLogThrottle $logThrottle): ShopwiredTransportInterface
    {
        return self::$transport ??= self::createTransport($logThrottle);
    }

    /**
     * Create the HTTP transport with optional logging decorator.
     *
     * When SHOPWIRED_LOG_LEVEL is set, wraps the base transport with a
     * logging decorator for debugging. Otherwise returns base transport
     * directly (zero overhead in production).
     *
     * @throws InvalidConfigurationException When credentials missing or log level invalid
     */
    private static function createTransport(TransientLogThrottle $logThrottle): ShopwiredTransportInterface
    {
        $apiKey = \config('shopwired.api_key');
        $apiSecret = \config('shopwired.api_secret');

        if (!\is_string($apiKey) || ($apiKey === '')) {
            throw new InvalidConfigurationException('SHOPWIRED_API_KEY');
        }

        if (!\is_string($apiSecret) || ($apiSecret === '')) {
            throw new InvalidConfigurationException('SHOPWIRED_API_SECRET');
        }

        $config = new ShopwiredConfig(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            timeout: Config::integer('shopwired.timeout', 30),
        );

        $errorHandler = new ShopwiredErrorHandler($logThrottle);
        $baseTransport = new ShopwiredHttpTransport($config, $errorHandler);

        $logLevel = \config('shopwired.log_level');

        if (!\is_string($logLevel) || $logLevel === '') {
            return $baseTransport;
        }

        $parsedLogLevel = ShopwiredLogLevel::tryFrom($logLevel);

        if ($parsedLogLevel === null) {
            throw new InvalidConfigurationException(
                'SHOPWIRED_LOG_LEVEL',
                "Invalid value '{$logLevel}'. Must be 'info' or 'debug'.",
            );
        }

        return new LoggingShopwiredTransport($baseTransport, $parsedLogLevel);
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
