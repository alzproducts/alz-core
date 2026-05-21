<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\CreditTierLabels\CreditTierLabelChangeDTO;
use App\Application\Catalog\Enums\CreditTier;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SyncCreditTierLabelUseCase
{
    public function __construct(
        private ProductViewQueryRepositoryInterface $productViewQueryRepo,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $this->logger->info('SyncCreditTierLabel: checking for label drift');

        $changes = $this->productViewQueryRepo->findCreditTierChanges();

        if ($changes === []) {
            $this->logger->info('SyncCreditTierLabel: no label changes needed');

            return;
        }

        $counts = $this->dispatchAndCount($changes);

        $this->logger->info('SyncCreditTierLabel: dispatched label updates', $counts);
    }

    /**
     * @param list<CreditTierLabelChangeDTO> $changes
     * @return array<string, int>
     */
    private function dispatchAndCount(array $changes): array
    {
        $counts = [
            'dispatched_tier1'   => 0,
            'dispatched_tier2'   => 0,
            'dispatched_tier3'   => 0,
            'dispatched_cleared' => 0,
        ];

        foreach ($changes as $change) {
            $this->dispatcher->dispatchCreditTierLabelUpdate($change->productId, $change->targetTier);
            ++$counts[self::tierToLogKey($change->targetTier)];
        }

        return $counts;
    }

    /**
     * @return 'dispatched_tier1'|'dispatched_tier2'|'dispatched_tier3'|'dispatched_cleared'
     */
    private static function tierToLogKey(?CreditTier $tier): string
    {
        return match ($tier) {
            CreditTier::Tier1 => 'dispatched_tier1',
            CreditTier::Tier2 => 'dispatched_tier2',
            CreditTier::Tier3 => 'dispatched_tier3',
            null              => 'dispatched_cleared',
        };
    }
}
