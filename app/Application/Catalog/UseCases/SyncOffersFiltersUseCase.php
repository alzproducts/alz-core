<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\OffersFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate hourly sync of ShopWired "Offers → On Sale" product filters.
 *
 * Queries the merge-preserving SQL view for products whose Offers filter slot
 * has drifted from the canonical sale-active rule, then dispatches one
 * per-entity job per product to apply the update.
 */
final readonly class SyncOffersFiltersUseCase
{
    public function __construct(
        private OffersFilterQueryRepositoryInterface $offersFilterRepo,
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
        $this->logger->info('SyncOffersFilters: starting');

        $changes = $this->offersFilterRepo->getProductsWithChangedOffersFilters();

        if ($changes === []) {
            $this->logger->info('SyncOffersFilters: no products with changed Offers filters');

            return;
        }

        $this->dispatchAll($changes);

        $this->logger->info('SyncOffersFilters: dispatched Offers filter updates', [
            'count' => \count($changes),
        ]);
    }

    /** @param list<ProductFilterChangeDTO> $changes */
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
