<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Catalog\UseCases\SyncBestSellersCategoryUseCase;
use App\Application\Contracts\Catalog\BestSellersRankingStateQueryRepositoryInterface;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\OffersFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Application\Contracts\Catalog\ProductPopularityRankingSnapshotRepositoryInterface;
use App\Application\Contracts\Catalog\ProductSortOrderQueryRepositoryInterface;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\RelatedProductsAlgorithmParamsRepositoryInterface;
use App\Application\Contracts\Catalog\RelatedProductsQueryRepositoryInterface;
use App\Application\Contracts\Catalog\ShippingOffersFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\ShippingOptionsFilterQueryRepositoryInterface;
use App\Application\Contracts\Catalog\VatReliefFilterQueryRepositoryInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Catalog\Dispatchers\QueuedCatalogSyncDispatcher;
use App\Infrastructure\Catalog\Product\Repositories\EloquentProductExtraDataRepository;
use App\Infrastructure\Catalog\Repositories\BestSellersRankingStateQueryRepository;
use App\Infrastructure\Catalog\Repositories\OffersFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\ProductPopularityRankingSnapshotRepository;
use App\Infrastructure\Catalog\Repositories\ProductSortOrderQueryRepository;
use App\Infrastructure\Catalog\Repositories\ProductViewQueryRepository;
use App\Infrastructure\Catalog\Repositories\RatingFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\RelatedProductsAlgorithmParamsRepository;
use App\Infrastructure\Catalog\Repositories\RelatedProductsQueryRepository;
use App\Infrastructure\Catalog\Repositories\ShippingOffersFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\ShippingOptionsFilterQueryRepository;
use App\Infrastructure\Catalog\Repositories\VatReliefFilterQueryRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

final class CatalogServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->registerRepositories();
        $this->registerBestSellersBindings();
        $this->registerRelatedProductsRepositories();

        $this->app->scoped(
            CatalogSyncDispatcherInterface::class,
            QueuedCatalogSyncDispatcher::class,
        );
    }

    /** @return list<class-string> */
    #[Override]
    public function provides(): array
    {
        return [
            BestSellersRankingStateQueryRepositoryInterface::class,
            RatingFilterQueryRepositoryInterface::class,
            VatReliefFilterQueryRepositoryInterface::class,
            OffersFilterQueryRepositoryInterface::class,
            ShippingOffersFilterQueryRepositoryInterface::class,
            ShippingOptionsFilterQueryRepositoryInterface::class,
            CatalogSyncDispatcherInterface::class,
            ProductExtraDataRepositoryInterface::class,
            ProductPopularityRankingSnapshotRepositoryInterface::class,
            ProductSortOrderQueryRepositoryInterface::class,
            RelatedProductsAlgorithmParamsRepositoryInterface::class,
            RelatedProductsQueryRepositoryInterface::class,
            ProductViewQueryRepositoryInterface::class,
        ];
    }

    private function registerRepositories(): void
    {
        $this->registerProductAttributeFilterRepositories();
        $this->registerShippingFilterRepositories();
        $this->registerSortOrderRepositories();
        $this->registerBestSellersRepositories();

        $this->app->scoped(
            ProductExtraDataRepositoryInterface::class,
            EloquentProductExtraDataRepository::class,
        );

        $this->app->scoped(
            ProductPopularityRankingSnapshotRepositoryInterface::class,
            ProductPopularityRankingSnapshotRepository::class,
        );
    }

    private function registerBestSellersRepositories(): void
    {
        $this->app->scoped(
            BestSellersRankingStateQueryRepositoryInterface::class,
            BestSellersRankingStateQueryRepository::class,
        );
    }

    private function registerBestSellersBindings(): void
    {
        $this->app->when(SyncBestSellersCategoryUseCase::class)
            ->needs('$bestSellersLimit')
            ->give(static fn(): int => self::resolveNumericConfig('shopwired.best_sellers_limit'));

        $this->app->when(SyncBestSellersCategoryUseCase::class)
            ->needs('$bestSellersCategoryId')
            ->give(static fn(): int => self::resolveNumericConfig('shopwired.best_sellers_category_id'));
    }

    private function registerSortOrderRepositories(): void
    {
        $this->app->scoped(
            ProductSortOrderQueryRepositoryInterface::class,
            ProductSortOrderQueryRepository::class,
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

    private function registerRelatedProductsRepositories(): void
    {
        $this->app->scoped(
            RelatedProductsAlgorithmParamsRepositoryInterface::class,
            RelatedProductsAlgorithmParamsRepository::class,
        );

        $this->app->scoped(
            RelatedProductsQueryRepositoryInterface::class,
            RelatedProductsQueryRepository::class,
        );

        $this->app->scoped(
            ProductViewQueryRepositoryInterface::class,
            ProductViewQueryRepository::class,
        );
    }

    private static function resolveNumericConfig(string $key): int
    {
        $value = \config($key);

        if (! \is_numeric($value)) {
            throw new InvalidConfigurationException($key, "{$key} must be a numeric value");
        }

        return (int) $value;
    }
}
