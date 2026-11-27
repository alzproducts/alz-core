<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimRulesDTO;
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
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Throwable;

/**
 * Claims the references of a chunk that the row-state rules allow, and settles the rest. Claims nothing past the deadline.
 */
final readonly class OrderSyncClaimService
{
    public function __construct(
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncQueryRepositoryInterface $orderSyncStates,
        private OrderSyncRepositoryInterface $orderSyncs,
        private OrderSyncRecoveryService $recovery,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function claim(OrderChunkRunDTO $run, Deadline $deadline): OrderClaimResult
    {
        $chunk = $run->chunk;
        $rows = $this->sourceOrders->findByReferences($chunk->references);
        $states = $this->orderSyncStates->statesFor($chunk->realmId, $chunk->references);

        $orders = [];
        try {
            foreach ($chunk->references as $reference) {
                $row = $rows[$reference] ?? null;
                $orders[] = self::refusal($reference, $states[$reference] ?? null, $row, $run->claimRules())
                    ?? $this->claimInTime($run, $reference, $row, $deadline);
            }
        } catch (Throwable $e) {
            $this->recovery->recover(OrderClaimResult::of($orders)->claimed, $e);

            throw $e;
        }

        return OrderClaimResult::of($orders);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function claimInTime(OrderChunkRunDTO $run, int $reference, ?SourceOrderRowDTO $row, Deadline $deadline): ClaimedOrderDTO|OrderSyncResultDTO
    {
        if ($row !== null && $deadline->secondsLeft(new DateTimeImmutable()) <= 0) {
            return OrderSyncResultDTO::refused($reference, SyncReasonCode::DeadlineExceeded);
        }

        return $this->claimOne($run, $reference, $row);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function claimOne(OrderChunkRunDTO $run, int $reference, ?SourceOrderRowDTO $row): ClaimedOrderDTO|OrderSyncResultDTO
    {
        $chunk = $run->chunk;
        if ($row === null) {
            return OrderSyncResultDTO::refused($reference, SyncReasonCode::OrderNotFound);
        }

        $claim = $this->orderSyncs->claim($chunk->realmId, $reference, $row->externalId, $run->claimRules());
        if ($claim !== null) {
            return new ClaimedOrderDTO($row, $claim, $run->isLastAttempt());
        }

        // Another worker changed the row between the read and the claim, or a re-attempt refused its unknown outcome.
        $state = $this->orderSyncStates->statesFor($chunk->realmId, [$reference])[$reference] ?? null;

        return self::refusal($reference, $state, $row, $run->claimRules())
            ?? OrderSyncResultDTO::refused($reference, SyncReasonCode::InProgress);
    }

    /**
     * Null when the reference is claimable.
     */
    private static function refusal(int $reference, ?OrderSyncStateDTO $state, ?SourceOrderRowDTO $row, OrderSyncClaimRulesDTO $rules): ?OrderSyncResultDTO
    {
        $eligibility = OrderEligibilityResolver::resolve($state, $row?->status, $rules);
        if ($eligibility->outcome === SyncOutcome::AlreadySynced) {
            return OrderSyncResultDTO::alreadySynced($reference, $state?->docType, $state?->qboDocNumber, $state?->qboTxnId);
        }

        return $eligibility->reason === null ? null : OrderSyncResultDTO::refused($reference, $eligibility->reason);
    }
}
