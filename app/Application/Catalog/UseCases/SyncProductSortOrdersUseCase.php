<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\ProductSortOrderChangeCommand;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ProductSortOrderQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate daily sync of product sort orders to ShopWired.
 *
 * Queries active products whose live sort_order differs from the latest
 * popularity snapshot, then dispatches one per-product job per difference.
 */
final readonly class SyncProductSortOrdersUseCase
{
    public function __construct(
        private ProductSortOrderQueryRepositoryInterface $sortOrderRepo,
        private CatalogSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SyncProductSortOrders: starting');

        $changes = $this->sortOrderRepo->getProductsWithSortOrderDifferences();

        if ($changes === []) {
            $this->logger->info('SyncProductSortOrders: no products with sort order differences');

            return;
        }

        $this->dispatchAll($changes);

        $this->logger->info('SyncProductSortOrders: dispatched sort order updates', [
            'count' => \count($changes),
        ]);
    }

    /** @param list<ProductSortOrderChangeCommand> $changes */
    private function dispatchAll(array $changes): void
    {
        foreach ($changes as $change) {
            $this->dispatcher->dispatchSortOrderUpdate($change->productId, $change->sortOrder);
        }
    }
}
