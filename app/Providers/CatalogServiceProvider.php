<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\OffersFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\ShippingOffersFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\ShippingOptionsFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\VatReliefFilterQueryRepositoryInterface;
use App\Infrastructure\Catalog\Dispatchers\QueuedCatalogSyncDispatcher;
use App\Infrastructure\Catalog\Product\Repositories\EloquentProductExtraDataRepository;
use App\Infrastructure\Catalog\Repositories\OffersFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\RatingFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\ShippingOffersFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\ShippingOptionsFilterQueryRepository;
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
            OffersFilterQueryRepositoryInterface::class,
            ShippingOffersFilterQueryRepositoryInterface::class,
            ShippingOptionsFilterQueryRepositoryInterface::class,
            CatalogSyncDispatcherInterface::class,
            ProductExtraDataRepositoryInterface::class,
        ];
    }

    private function registerRepositories(): void
    {
        $this->registerProductAttributeFilterRepositories();
        $this->registerShippingFilterRepositories();

        $this->app->scoped(
            ProductExtraDataRepositoryInterface::class,
            EloquentProductExtraDataRepository::class,
        );
    }

    private function registerProductAttributeFilterRepositories(): void
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
            OffersFilterQueryRepositoryInterface::class,
            OffersFilterQueryRepository::class,
        );
    }

    private function registerShippingFilterRepositories(): void
    {
        $this->app->scoped(
            ShippingOffersFilterQueryRepositoryInterface::class,
            ShippingOffersFilterQueryRepository::class,
        );
        $this->app->scoped(
            ShippingOptionsFilterQueryRepositoryInterface::class,
            ShippingOptionsFilterQueryRepository::class,
        );
    }
}
