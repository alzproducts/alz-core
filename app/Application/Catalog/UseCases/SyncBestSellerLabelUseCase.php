<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\BestSellerLabels\BestSellerLabelAssignmentDTO;
use App\Application\Catalog\Enums\BestSellerLabel;
use App\Application\Catalog\Enums\CustomLabelField;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @extends AbstractDriftSyncUseCase<BestSellerLabelAssignmentDTO>
 */
final readonly class SyncBestSellerLabelUseCase extends AbstractDriftSyncUseCase
{
    public function __construct(
        private ProductViewQueryRepositoryInterface $productViewQueryRepo,
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

    /** @return list<BestSellerLabelAssignmentDTO> */
    #[Override]
    protected function fetchDrift(): array
    {
        $changes = $this->productViewQueryRepo->findBestSellerLabelChanges();

        $items = [];

        foreach ($changes->toAdd as $productId) {
            $items[] = new BestSellerLabelAssignmentDTO($productId, BestSellerLabel::BestSellers->value);
        }

        foreach ($changes->toRemove as $productId) {
            $items[] = new BestSellerLabelAssignmentDTO($productId, null);
        }

        return $items;
    }

    #[Override]
    protected function dispatchOne(object $item): void
    {
        /** @var BestSellerLabelAssignmentDTO $item */
        $this->dispatcher->dispatchLabelUpdate(
            $item->productId,
            CustomLabelField::BestSellers,
            $item->label,
        );
    }

    #[Override]
    protected function syncName(): string
    {
        return 'SyncBestSellerLabel';
    }

    #[Override]
    protected function countKey(object $item): ?string
    {
        /** @var BestSellerLabelAssignmentDTO $item */
        return $item->label !== null ? 'dispatched_add' : 'dispatched_remove';
    }
}
