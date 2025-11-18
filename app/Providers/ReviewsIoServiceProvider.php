<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Api\ReviewsIoClient;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

/**
 * Reviews.io API Client Service Provider
 *
 * Deferred provider for ReviewsIoClient - only loads when the service is requested.
 */
final class ReviewsIoServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function boot(): void
    {
        $isCi = \getenv('CI') !== false;

        if ($this->app->environment('production') && !$isCi) {
            self::validateProductionConfig();
        }
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
                'PRODUCTION DEPLOYMENT BLOCKED: Reviews.io missing config: '
                . \implode(', ', $missing),
            );
        }
    }

    /**
     * Register Reviews.io API client.
     *
     * Validates configuration in ReviewsIoClient constructor.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(ReviewsIoClient::class, static function (): ReviewsIoClient {
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
