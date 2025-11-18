<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Api\ReviewsIo\ReviewsIoClient;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

/**
 * Reviews.io API Client Service Provider
 *
 * Deferred provider for ReviewsIoClient - only loads when the service is requested.
 * Configuration is validated lazily on first resolution, not on boot.
 */
final class ReviewsIoServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Reviews.io API client.
     *
     * Validates configuration lazily when the service is first resolved.
     * This defers validation until the service is actually needed, allowing
     * other application features to function even if Reviews.io config is missing.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(ReviewsIoClient::class, static function (Application $app): ReviewsIoClient {
            if ($app->environment('production') && (\getenv('CI') === false)) {
                self::validateProductionConfig();
            }

            /** @var array{api_key: string|null, store_id: string|null, timeout: int, retry_times: int, retry_delay: int, enabled: bool} $config */
            $config = \config('reviewsio');

            return new ReviewsIoClient(
                apiKey: $config['api_key'] ?? '',
                storeId: $config['store_id'] ?? '',
                timeout: $config['timeout'],
                retryTimes: $config['retry_times'],
                retryDelay: $config['retry_delay'],
            );
        });
    }

    private static function validateProductionConfig(): void
    {
        $apiKey = \config('reviewsio.api_key');
        $storeId = \config('reviewsio.store_id');

        $missing = [];

        if (!\is_string($apiKey) || ($apiKey === '')) {
            $missing[] = 'Reviews.io API key (REVIEWSIO_API_KEY)';
        }

        if (!\is_string($storeId) || ($storeId === '')) {
            $missing[] = 'Reviews.io store ID (REVIEWSIO_STORE_ID)';
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Reviews.io missing required configuration: '
                . \implode(', ', $missing),
            );
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [ReviewsIoClient::class];
    }
}
