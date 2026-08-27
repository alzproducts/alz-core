<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\CreditTierLabelChangeDTO;
use App\Application\Catalog\Enums\CreditTier;
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
 * @extends AbstractDriftSyncUseCase<CreditTierLabelChangeDTO>
 */
final readonly class SyncCreditTierLabelUseCase extends AbstractDriftSyncUseCase
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

    /** @return list<CreditTierLabelChangeDTO> */
    #[Override]
    protected function fetchDrift(): array
    {
        return $this->productViewQueryRepo->findCreditTierChanges();
    }

    #[Override]
    protected function dispatchOne(object $item): void
    {
        /** @var CreditTierLabelChangeDTO $item */
        $this->dispatcher->dispatchLabelUpdate(
            $item->productId,
            CustomLabelField::CreditTier,
            $item->targetTier?->value,
        );
    }

    #[Override]
    protected function syncName(): string
    {
        return 'SyncCreditTierLabel';
    }

    #[Override]
    protected function countKey(object $item): string
    {
        /** @var CreditTierLabelChangeDTO $item */
        return match ($item->targetTier) {
            CreditTier::Tier1 => 'dispatched_tier1',
            CreditTier::Tier2 => 'dispatched_tier2',
            CreditTier::Tier3 => 'dispatched_tier3',
            null              => 'dispatched_cleared',
        };
    }
}
