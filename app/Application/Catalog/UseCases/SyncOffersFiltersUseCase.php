<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\OffersFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @extends AbstractDriftSyncUseCase<ProductFilterChangeCommand>
 */
final readonly class SyncOffersFiltersUseCase extends AbstractDriftSyncUseCase
{
    public function __construct(
        private OffersFilterQueryRepositoryInterface $offersFilterRepo,
        private CatalogSyncDispatcherInterface $dispatcher,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function execute(): void
    {
        $this->process();
    }

    /** @return list<ProductFilterChangeCommand> */
    #[Override]
    protected function fetchDrift(): array
    {
        return $this->offersFilterRepo->getProductsWithChangedOffersFilters();
    }

    #[Override]
    protected function dispatchOne(object $item): void
    {
        /** @var ProductFilterChangeCommand $item */
        $this->dispatcher->dispatchFilterUpdate(
            $item->productId,
            $item->optionNo,
            $item->filterValuesForDispatch(),
        );
    }

    #[Override]
    protected function syncName(): string
    {
        return 'SyncOffersFilters';
    }
}
