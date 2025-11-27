<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Infrastructure\Shopwired\Clients\CategoryClient;
use App\Infrastructure\Shopwired\Clients\CustomerClient;
use RuntimeException;

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
    private static ?ShopwiredHttpTransport $transport = null;

    /**
     * Create the connectivity client for API health checks.
     */
    public static function createConnectivityClient(): ConnectivityClientInterface
    {
        return new ShopwiredClient(self::getTransport());
    }

    /**
     * Create the category client for category operations.
     */
    public static function createCategoryClient(): CategoryClientInterface
    {
        return new CategoryClient(self::getTransport());
    }

    /**
     * Create the customer client for customer operations.
     */
    public static function createCustomerClient(): CustomerClientInterface
    {
        return new CustomerClient(self::getTransport());
    }

    /**
     * Get the shared HTTP transport (lazy singleton).
     *
     * Creates the transport on first access, reuses for subsequent calls.
     * This ensures all clients share the same transport instance.
     */
    private static function getTransport(): ShopwiredHttpTransport
    {
        return self::$transport ??= self::createTransport();
    }

    /**
     * Create the HTTP transport with validated configuration.
     */
    private static function createTransport(): ShopwiredHttpTransport
    {
        $apiKey = \config('shopwired.api_key');
        $apiSecret = \config('shopwired.api_secret');

        if (! \is_string($apiKey) || ($apiKey === '')) {
            throw new RuntimeException('SHOPWIRED_API_KEY not configured');
        }

        if (! \is_string($apiSecret) || ($apiSecret === '')) {
            throw new RuntimeException('SHOPWIRED_API_SECRET not configured');
        }

        $config = new ShopwiredConfig(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            timeout: self::getIntConfig('timeout', 30),
            retryTimes: self::getIntConfig('retry_times', 3),
            retryDelay: self::getIntConfig('retry_delay', 100),
        );

        return new ShopwiredHttpTransport($config);
    }

    /**
     * Get integer config value with fallback.
     */
    private static function getIntConfig(string $key, int $default): int
    {
        $value = \config("shopwired.{$key}");

        return \is_int($value) ? $value : $default;
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
