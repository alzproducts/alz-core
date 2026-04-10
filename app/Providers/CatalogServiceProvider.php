<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\VatReliefFilterQueryRepositoryInterface;
use App\Infrastructure\Catalog\Dispatchers\QueuedCatalogSyncDispatcher;
use App\Infrastructure\Catalog\Product\Repositories\EloquentProductExtraDataRepository;
use App\Infrastructure\Catalog\Repositories\RatingFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\VatReliefFilterQueryRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->registerRepositories();

        $this->app->scoped(
            CatalogSyncDispatcherInterface::class,
            QueuedCatalogSyncDispatcher::class,
        );
    }

    /** @return list<class-string> */
    public function provides(): array
    {
        return [
            RatingFilterQueryRepositoryInterface::class,
            VatReliefFilterQueryRepositoryInterface::class,
            CatalogSyncDispatcherInterface::class,
            ProductExtraDataRepositoryInterface::class,
        ];
    }

    private function registerRepositories(): void
    {
        $this->app->scoped(
            RatingFilterQueryRepositoryInterface::class,
            RatingFilterQueryRepository::class,
        );

        $this->app->scoped(
            VatReliefFilterQueryRepositoryInterface::class,
            VatReliefFilterQueryRepository::class,
        );

        $this->app->scoped(
            ProductExtraDataRepositoryInterface::class,
            EloquentProductExtraDataRepository::class,
        );
    }
}
