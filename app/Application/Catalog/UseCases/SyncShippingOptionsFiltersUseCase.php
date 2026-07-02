<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ShippingOptionsFilterQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @extends AbstractDriftSyncUseCase<ProductFilterChangeCommand>
 */
final readonly class SyncShippingOptionsFiltersUseCase extends AbstractDriftSyncUseCase
{
    public function __construct(
        private ShippingOptionsFilterQueryRepositoryInterface $shippingOptionsFilterRepo,
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
        return $this->shippingOptionsFilterRepo->getProductsWithChangedShippingOptionsFilters();
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
        return 'SyncShippingOptionsFilters';
    }
}
