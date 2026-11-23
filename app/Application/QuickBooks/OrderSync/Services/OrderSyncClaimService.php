<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncStateDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderEligibilityResolver;
use App\Application\QuickBooks\OrderSync\Results\OrderClaimResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Claims the references of a chunk that the row-state rules allow, and settles the rest.
 */
final readonly class OrderSyncClaimService
{
    public function __construct(
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncQueryRepositoryInterface $orderSyncStates,
        private OrderSyncRepositoryInterface $orderSyncs,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function claim(OrderSyncChunkDTO $chunk): OrderClaimResult
    {
        $rows = $this->sourceOrders->findByReferences($chunk->references);
        $states = $this->orderSyncStates->statesFor($chunk->realmId, $chunk->references);

        $claimed = [];
        $refused = [];
        foreach ($chunk->references as $reference) {
            $row = $rows[$reference] ?? null;
            $order = self::refusal($reference, $states[$reference] ?? null, $row, $chunk->forceRetry) ?? $this->claimOne($chunk, $reference, $row);
            if ($order instanceof ClaimedOrderDTO) {
                $claimed[] = $order;
            } else {
                $refused[] = $order;
            }
        }

        return new OrderClaimResult($claimed, $refused);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function claimOne(OrderSyncChunkDTO $chunk, int $reference, ?SourceOrderRowDTO $row): ClaimedOrderDTO|OrderSyncResultDTO
    {
        if ($row === null) {
            return OrderSyncResultDTO::refused($reference, SyncReasonCode::OrderNotFound);
        }

        $claim = $this->orderSyncs->claim($chunk->realmId, $reference, $row->externalId, $chunk->forceRetry);
        if ($claim !== null) {
            return new ClaimedOrderDTO($row, $claim);
        }

        // Another worker changed the row between the read and the claim.
        $state = $this->orderSyncStates->statesFor($chunk->realmId, [$reference])[$reference] ?? null;

        return self::refusal($reference, $state, $row, $chunk->forceRetry)
            ?? OrderSyncResultDTO::refused($reference, SyncReasonCode::InProgress);
    }

    /**
     * Null when the reference is claimable.
     */
    private static function refusal(int $reference, ?OrderSyncStateDTO $state, ?SourceOrderRowDTO $row, bool $forceRetry): ?OrderSyncResultDTO
    {
        $eligibility = OrderEligibilityResolver::resolve($state, $row?->status, $forceRetry);
        if ($eligibility->outcome === SyncOutcome::AlreadySynced) {
            return OrderSyncResultDTO::alreadySynced($reference, $state?->docType, $state?->qboDocNumber, $state?->qboTxnId);
        }

        return $eligibility->reason === null ? null : OrderSyncResultDTO::refused($reference, $eligibility->reason);
    }
}
