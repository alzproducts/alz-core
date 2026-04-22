<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\RatingFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate hourly sync of product rating filters to ShopWired.
 *
 * Queries the SQL view for products whose rating filter values have changed,
 * then dispatches one per-entity job per product to apply the update.
 */
final readonly class SyncRatingFiltersUseCase
{
    public function __construct(
        private RatingFilterQueryRepositoryInterface $ratingFilterRepo,
        private CatalogSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function execute(): void
    {
        $this->logger->info('SyncRatingFilters: starting');

        $changes = $this->ratingFilterRepo->getProductsWithChangedRatingFilters();

        if ($changes === []) {
            $this->logger->info('SyncRatingFilters: no products with changed rating filters');

            return;
        }

        $this->dispatchAll($changes);

        $this->logger->info('SyncRatingFilters: dispatched rating filter updates', [
            'count' => \count($changes),
        ]);
    }

    /** @param list<ProductFilterChangeCommand> $changes */
    private function dispatchAll(array $changes): void
    {
        foreach ($changes as $change) {
            $this->dispatcher->dispatchFilterUpdate(
                $change->productId,
                $change->optionNo,
                $change->filterValuesForDispatch(),
            );
        }
    }
}
