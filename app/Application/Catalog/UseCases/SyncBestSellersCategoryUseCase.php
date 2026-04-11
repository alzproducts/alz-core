<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Catalog\BestSellersRankingStateQueryRepositoryInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

final readonly class SyncBestSellersCategoryUseCase
{
    public function __construct(
        private BestSellersRankingStateQueryRepositoryInterface $rankingRepo,
        private ProductRepositoryInterface $productRepo,
        private CategoryRepositoryInterface $categoryRepo,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
        private int $bestSellersLimit,
        private int $bestSellersCategoryId,
    ) {}

    /**
     * @throws ResourceNotFoundException When the Best Sellers category is missing or inactive
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SyncBestSellersCategory: starting', [
            'category_id' => $this->bestSellersCategoryId,
            'limit' => $this->bestSellersLimit,
        ]);

        $this->guardBestSellersCategory();

        $topIds = $this->rankingRepo->findTopRankedProductIds($this->bestSellersLimit);

        if ($topIds === []) {
            $this->logger->info('SyncBestSellersCategory: no ranking snapshot yet — skipping');

            return;
        }

        $currentIds = $this->productRepo->findExternalIdsInCategory($this->bestSellersCategoryId);
        $this->dispatchDiff($topIds, $currentIds);
    }

    /**
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    private function guardBestSellersCategory(): void
    {
        $category = $this->categoryRepo->findByExternalId($this->bestSellersCategoryId);

        if ($category === null || ! $category->active) {
            throw new ResourceNotFoundException('shopwired', 'Category', $this->bestSellersCategoryId);
        }
    }

    /**
     * @param  list<int>  $topIds
     * @param  list<int>  $currentIds
     */
    private function dispatchDiff(array $topIds, array $currentIds): void
    {
        $toAdd = \array_values(\array_diff($topIds, $currentIds));
        $toRemove = \array_values(\array_diff($currentIds, $topIds));

        $this->dispatchAdds($toAdd);
        $this->dispatchRemoves($toRemove);

        $this->logger->info('SyncBestSellersCategory: dispatched membership updates', [
            'add_count' => \count($toAdd),
            'remove_count' => \count($toRemove),
        ]);
    }

    /**
     * @param  list<int>  $productIds
     */
    private function dispatchAdds(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->dispatcher->dispatchCategoryMembershipUpdate(
                IntId::from($productId),
                [$this->bestSellersCategoryId],
                [],
            );
        }
    }

    /**
     * @param  list<int>  $productIds
     */
    private function dispatchRemoves(array $productIds): void
    {
        foreach ($productIds as $productId) {
            $this->dispatcher->dispatchCategoryMembershipUpdate(
                IntId::from($productId),
                [],
                [$this->bestSellersCategoryId],
            );
        }
    }
}
