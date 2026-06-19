<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\DTOs\MarginTierAssignmentDTO;
use App\Application\Catalog\Enums\MarginTier;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class SyncMarginTierLabelUseCase
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
        $this->logger->info('SyncMarginTierLabel: checking for label drift');

        $drift = $this->productViewQueryRepo->findMarginTierDrift();

        if ($drift === []) {
            $this->logger->info('SyncMarginTierLabel: no label changes needed');

            return;
        }

        $counts = $this->dispatchAndCount($drift);

        $this->logger->info('SyncMarginTierLabel: dispatched label updates', $counts);
    }

    /**
     * @param list<MarginTierAssignmentDTO> $drift
     * @return array<string, int>
     */
    private function dispatchAndCount(array $drift): array
    {
        $countByTier = [];

        foreach ($drift as $assignment) {
            $this->dispatcher->dispatchMarginTierLabelUpdate($assignment->productId, $assignment->targetLabel);
            $countByTier[$assignment->targetLabel->value] = ($countByTier[$assignment->targetLabel->value] ?? 0) + 1;
        }

        return [
            'dispatched_low' => $countByTier[MarginTier::Low->value] ?? 0,
            'dispatched_standard' => $countByTier[MarginTier::Standard->value] ?? 0,
            'dispatched_high' => $countByTier[MarginTier::High->value] ?? 0,
            'dispatched_unknown' => $countByTier[MarginTier::Unknown->value] ?? 0,
        ];
    }
}
