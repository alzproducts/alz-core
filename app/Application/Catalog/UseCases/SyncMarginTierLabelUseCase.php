<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\MarginTierAssignmentDTO;
use App\Application\Catalog\Enums\CustomLabelField;
use App\Application\Catalog\Enums\MarginTier;
use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Override;
use Psr\Log\LoggerInterface;

/**
 * @extends AbstractDriftSyncUseCase<MarginTierAssignmentDTO>
 */
final readonly class SyncMarginTierLabelUseCase extends AbstractDriftSyncUseCase
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

    /** @return list<MarginTierAssignmentDTO> */
    #[Override]
    protected function fetchDrift(): array
    {
        return $this->productViewQueryRepo->findMarginTierDrift();
    }

    #[Override]
    protected function dispatchOne(object $item): void
    {
        /** @var MarginTierAssignmentDTO $item */
        $this->dispatcher->dispatchLabelUpdate(
            $item->productId,
            CustomLabelField::MarginTier,
            $item->targetLabel->value,
        );
    }

    #[Override]
    protected function syncName(): string
    {
        return 'SyncMarginTierLabel';
    }

    #[Override]
    protected function countKey(object $item): ?string
    {
        /** @var MarginTierAssignmentDTO $item */
        return 'dispatched_' . match ($item->targetLabel) {
            MarginTier::Low => 'low',
            MarginTier::Standard => 'standard',
            MarginTier::High => 'high',
            MarginTier::Unknown => 'unknown',
        };
    }
}
