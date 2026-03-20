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
    public static function createConnectivityClient(): ConnectivityClientInterface
    {
        return new ShopwiredClient(self::getTransport());
    }

    /**
     * Create the brand client for brand operations.
     */
    public static function createBrandClient(): BrandClientInterface
    {
        return new BrandClient(self::getTransport());
    }

    /**
     * Create the category client for category operations.
     */
    public static function createCategoryClient(): CategoryClientInterface
    {
        return new CategoryClient(self::getTransport());
    }

    /**
     * Create the custom field client for custom field definitions.
     */
    public static function createCustomFieldClient(): CustomFieldClientInterface
    {
        return new CustomFieldClient(self::getTransport());
    }

    /**
     * Create the filter group client for filter group definitions.
     */
    public static function createFilterGroupClient(): FilterGroupClientInterface
    {
        return new FilterGroupClient(self::getTransport());
    }

    /**
     * Create the customer client for customer operations.
     */
    public static function createCustomerClient(): CustomerClientInterface
    {
        return new CustomerClient(self::getTransport());
    }

    /**
     * Create the order client for order operations.
     */
    public static function createOrderClient(): OrderClientInterface
    {
        return new OrderClient(self::getTransport());
    }

    /**
     * Create the stock client for stock quantity updates.
     */
    public static function createStockClient(): StockClientInterface
    {
        return new StockClient(self::getTransport());
    }

    /**
     * Create the price update client for batch price updates.
     */
    public static function createPriceUpdateClient(): PriceUpdateClientInterface
    {
        return new PriceUpdateClient(self::getTransport());
    }

    /**
     * Create the webhook client for webhook health monitoring.
     */
    public static function createWebhookClient(): WebhookClientInterface
    {
        return new WebhookClient(self::getTransport());
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
    public static function getTransport(): ShopwiredTransportInterface
    {
        return self::$transport ??= self::createTransport();
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
    private static function createTransport(): ShopwiredTransportInterface
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

        $baseTransport = new ShopwiredHttpTransport($config);

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
