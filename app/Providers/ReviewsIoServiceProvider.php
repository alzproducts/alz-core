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
     * Uses lazy type casting - validation happens in ReviewsIoClient constructor.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(ReviewsIoClient::class, static function (): ReviewsIoClient {
            $apiKey     = \config('services.reviewsio.api_key');
            $store      = \config('services.reviewsio.store');
            $timeout    = \config('services.reviewsio.timeout', 30);
            $retryTimes = \config('services.reviewsio.retry_times', 3);
            $retryDelay = \config('services.reviewsio.retry_delay', 100);

            return new ReviewsIoClient(
                apiKey: \is_string($apiKey) ? $apiKey : '',
                storeId: \is_string($store) ? $store : '',
                timeout: \is_int(
                    $timeout,
                ) ? $timeout : 30,
                retryTimes: \is_int($retryTimes) ? $retryTimes : 3,
                retryDelay: \is_int($retryDelay)
                    ? $retryDelay : 100,
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
