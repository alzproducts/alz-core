<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\VatReliefFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate hourly sync of VAT-relief product filters to ShopWired.
 *
 * Queries the SQL view for products whose VAT-relief filter value has changed,
 * then dispatches one per-entity job per product to apply the update.
 */
final readonly class SyncVatReliefFiltersUseCase
{
    public function __construct(
        private VatReliefFilterQueryRepositoryInterface $vatReliefFilterRepo,
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
        $this->logger->info('SyncVatReliefFilters: starting');

        $changes = $this->vatReliefFilterRepo->getProductsWithChangedVatReliefFilters();

        if ($changes === []) {
            $this->logger->info('SyncVatReliefFilters: no products with changed VAT-relief filters');

            return;
        }

        $this->dispatchAll($changes);

        $this->logger->info('SyncVatReliefFilters: dispatched VAT-relief filter updates', [
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
