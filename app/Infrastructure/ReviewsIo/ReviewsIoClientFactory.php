<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Application\Contracts\ReviewsIoClientInterface;
use RuntimeException;

/**
 * Factory for creating ReviewsIoClient with all dependencies.
 *
 * Centralizes configuration validation and dependency wiring.
 * The factory reads from Laravel config and constructs the full
 * dependency chain: Config → Transport → Client.
 *
 * @template-pattern API Client Factory
 */
final class ReviewsIoClientFactory
{
    /**
     * Create a fully configured ReviewsIoClient instance.
     */
    public static function create(): ReviewsIoClientInterface
    {
        $apiKey = \config('reviewsio.api_key');
        $storeId = \config('reviewsio.store_id');

        if (! \is_string($apiKey) || ($apiKey === '')) {
            throw new RuntimeException('REVIEWSIO_API_KEY not configured');
        }

        if (! \is_string($storeId) || ($storeId === '')) {
            throw new RuntimeException('REVIEWSIO_STORE not configured');
        }

        $config = new ReviewsIoConfig(
            apiKey: $apiKey,
            storeId: $storeId,
            timeout: self::getIntConfig('timeout', 30),
            retryTimes: self::getIntConfig('retry_times', 3),
            retryDelay: self::getIntConfig('retry_delay', 100),
        );

        $transport = new ReviewsIoHttpTransport($config);

        return new ReviewsIoClient($transport);
    }

    /**
     * Get integer config value with fallback.
     */
    private static function getIntConfig(string $key, int $default): int
    {
        $value = \config("reviewsio.{$key}");

        return \is_int($value) ? $value : $default;
    }
}
