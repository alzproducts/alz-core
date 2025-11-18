<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Api\ReviewsIoClient;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Reviews.io API Client Service Provider
 *
 * Deferred provider for ReviewsIoClient - only loads when the service is requested.
 */
final class ReviewsIoServiceProvider extends ServiceProvider implements DeferrableProvider
{
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
