<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncStateDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderEligibilityResolver;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Settles the references of a chunk that the row-state rules refuse.
 */
final readonly class OrderSyncClaimService
{
    public function __construct(
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncQueryRepositoryInterface $orderSyncStates,
    ) {}

    /**
     * @return list<OrderSyncResultDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function claim(OrderSyncChunkDTO $chunk): array
    {
        $rows = $this->sourceOrders->findByReferences($chunk->references);
        $states = $this->orderSyncStates->statesFor($chunk->realmId, $chunk->references);

        $results = [];
        foreach ($chunk->references as $reference) {
            $row = $rows[$reference] ?? null;
            $results[] = self::refusal($reference, $states[$reference] ?? null, $row?->status, $chunk->forceRetry)
                ?? OrderSyncResultDTO::refused($reference, SyncReasonCode::InProgress);
        }

        return $results;
    }

    /**
     * Null when the reference is claimable.
     */
    private static function refusal(int $reference, ?OrderSyncStateDTO $state, ?OrderLifecycleStatus $status, bool $forceRetry): ?OrderSyncResultDTO
    {
        $eligibility = OrderEligibilityResolver::resolve($state, $status, $forceRetry);
        if ($eligibility->outcome === SyncOutcome::AlreadySynced) {
            return OrderSyncResultDTO::alreadySynced($reference, $state?->docType, $state?->qboDocNumber, $state?->qboTxnId);
        }

        return $eligibility->reason === null ? null : OrderSyncResultDTO::refused($reference, $eligibility->reason);
    }
}
