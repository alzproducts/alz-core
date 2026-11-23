<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncFailureDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncFailureDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Records each outcome on its own row, and returns what QuickBooks gave even when the row could not take it.
 */
final readonly class OrderSyncRecordService
{
    public function __construct(
        private OrderSyncRepositoryInterface $orderSyncs,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<OrderSyncOutcomeDTO> $outcomes
     *
     * @return list<OrderSyncResultDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function record(OrderSyncChunkDTO $chunk, array $outcomes): array
    {
        $results = [];
        foreach ($outcomes as $outcome) {
            if (!$this->recordOne($chunk, $outcome)) {
                $this->logLostLease($outcome);
            }

            $results[] = OrderSyncResultDTO::ofOutcome($outcome);
        }

        return $results;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function recordOne(OrderSyncChunkDTO $chunk, OrderSyncOutcomeDTO $outcome): bool
    {
        $claim = $outcome->order->claim;
        $detail = $outcome->detail;

        return $detail instanceof OrderSyncPostedDTO
            ? $this->orderSyncs->recordSynced($claim->orderSyncId, $claim->claimToken, $detail)
            : $this->orderSyncs->recordFailed($claim->orderSyncId, $claim->claimToken, self::failure($chunk, $outcome, $detail));
    }

    private static function failure(OrderSyncChunkDTO $chunk, OrderSyncOutcomeDTO $outcome, SyncFailureDTO $failure): OrderSyncFailureDTO
    {
        return new OrderSyncFailureDTO(
            $failure->code,
            $failure->loggedCode,
            $failure->message,
            $outcome->docType,
            $failure->fault,
            $chunk->trigger,
            $chunk->runId,
        );
    }

    private function logLostLease(OrderSyncOutcomeDTO $outcome): void
    {
        $detail = $outcome->detail;
        $this->logger->error('QuickBooks order sync lease lost before its outcome was recorded', [
            'reference' => $outcome->order->row->reference,
            'outcome' => $outcome->outcome->value,
            'reason' => $detail instanceof SyncFailureDTO ? $detail->code->value : null,
            'qbo_txn_id' => $detail instanceof OrderSyncPostedDTO ? $detail->qboTxnId : null,
        ]);
    }
}
