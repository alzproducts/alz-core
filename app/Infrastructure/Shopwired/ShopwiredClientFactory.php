<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use RuntimeException;

/**
 * Factory for creating ShopwiredClient with all dependencies.
 *
 * Centralizes configuration validation and dependency wiring.
 * The factory reads from Laravel config and constructs the full
 * dependency chain: Config → Transport → Client.
 *
 * @template-pattern API Client Factory
 */
final class ShopwiredClientFactory
{
    /**
     * Create a fully configured ShopwiredClient instance.
     */
    public static function create(): ConnectivityClientInterface
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

        $transport = new ShopwiredHttpTransport($config);

        return new ShopwiredClient($transport);
    }

    /**
     * Get integer config value with fallback.
     */
    private static function getIntConfig(string $key, int $default): int
    {
        $value = \config("shopwired.{$key}");

        return \is_int($value) ? $value : $default;
    }
}
