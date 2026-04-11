<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ShippingOptionsFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SyncShippingOptionsFiltersUseCase
{
    public function __construct(
        private ShippingOptionsFilterQueryRepositoryInterface $shippingOptionsFilterRepo,
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
        $this->logger->info('SyncShippingOptionsFilters: starting');

        $changes = $this->shippingOptionsFilterRepo->getProductsWithChangedShippingOptionsFilters();

        if ($changes === []) {
            $this->logger->info('SyncShippingOptionsFilters: no products with changed Shipping Options filters');

            return;
        }

        $this->dispatchAll($changes);

        $this->logger->info('SyncShippingOptionsFilters: dispatched Shipping Options filter updates', [
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
