<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Infrastructure\Catalog\Dispatchers\QueuedCatalogSyncDispatcher;
use App\Infrastructure\Catalog\Product\Repositories\EloquentProductExtraDataRepository;
use App\Infrastructure\Catalog\Repositories\RatingFilterQueryRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->scoped(
            RatingFilterQueryRepositoryInterface::class,
            RatingFilterQueryRepository::class,
        );

        $this->app->scoped(
            CatalogSyncDispatcherInterface::class,
            QueuedCatalogSyncDispatcher::class,
        );

        $this->app->scoped(
            ProductExtraDataRepositoryInterface::class,
            EloquentProductExtraDataRepository::class,
        );
    }

    /** @return list<class-string> */
    public function provides(): array
    {
        return [
            RatingFilterQueryRepositoryInterface::class,
            CatalogSyncDispatcherInterface::class,
            ProductExtraDataRepositoryInterface::class,
        ];
    }
}
