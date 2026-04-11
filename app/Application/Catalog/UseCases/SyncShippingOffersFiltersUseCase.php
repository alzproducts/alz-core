<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ShippingOffersFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SyncShippingOffersFiltersUseCase
{
    public function __construct(
        private ShippingOffersFilterQueryRepositoryInterface $shippingOffersFilterRepo,
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
        $this->logger->info('SyncShippingOffersFilters: starting');

        $changes = $this->shippingOffersFilterRepo->getProductsWithChangedShippingOffersFilters();

        if ($changes === []) {
            $this->logger->info('SyncShippingOffersFilters: no products with changed Shipping Offers filters');

            return;
        }

        $this->dispatchAll($changes);

        $this->logger->info('SyncShippingOffersFilters: dispatched Shipping Offers filter updates', [
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
