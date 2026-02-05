<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ReviewsIo\ProductRatingRepositoryInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Infrastructure\ReviewsIo\Repositories\EloquentProductRatingRepository;
use App\Infrastructure\ReviewsIo\ReviewsIoClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Reviews.io API Client Service Provider.
 *
 * Deferred provider for ReviewsIoClient - only loads when the service is requested.
 * Configuration validation is handled by the Factory (fail-fast pattern).
 *
 * @template-pattern API Client Service Provider
 */
final class ReviewsIoServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Reviews.io API client.
     *
     * Delegates to ReviewsIoClientFactory which handles:
     * - Configuration validation (fail-fast with RuntimeException)
     * - Dependency wiring (Config → Transport → Client)
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            ReviewsIoClientInterface::class,
            static fn(): ReviewsIoClientInterface => ReviewsIoClientFactory::create(),
        );

        $this->app->scoped(
            ProductRatingRepositoryInterface::class,
            EloquentProductRatingRepository::class,
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ProductRatingRepositoryInterface::class,
            ReviewsIoClientInterface::class,
        ];
    }
}
