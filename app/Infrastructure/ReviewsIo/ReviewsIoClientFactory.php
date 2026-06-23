<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo;

use App\Application\Contracts\ReviewsIoClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Support\TransientLogThrottle;
use Illuminate\Support\Facades\Config;

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
        [$apiKey, $storeId] = self::validateCredentials();

        $config = new ReviewsIoConfig(
            apiKey: $apiKey,
            storeId: $storeId,
            timeout: Config::integer('reviewsio.timeout', 30),
            retryTimes: Config::integer('reviewsio.retry_times', 3),
            retryDelay: Config::integer('reviewsio.retry_delay', 100),
        );

        return new ReviewsIoClient(new ReviewsIoHttpTransport($config, \app(TransientLogThrottle::class)));
    }

    /**
     * @return array{string, string} [apiKey, storeId]
     *
     * @throws InvalidConfigurationException When credentials are missing
     */
    private static function validateCredentials(): array
    {
        $apiKey = \config('reviewsio.api_key');
        $storeId = \config('reviewsio.store_id');

        if (!\is_string($apiKey) || ($apiKey === '')) {
            throw new InvalidConfigurationException('REVIEWSIO_API_KEY');
        }

        if (!\is_string($storeId) || ($storeId === '')) {
            throw new InvalidConfigurationException('REVIEWSIO_STORE');
        }

        return [$apiKey, $storeId];
    }

}
