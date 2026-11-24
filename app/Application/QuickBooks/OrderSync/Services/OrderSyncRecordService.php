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
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records each outcome on its own row, posted documents first, and returns what QuickBooks gave even when the row could
 * not take it. A write that throws does not stop the others; the first throw is rethrown at the end.
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
        $thrown = null;
        foreach (self::postedFirst($outcomes) as $outcome) {
            try {
                $this->recordOne($chunk, $outcome);
            } catch (Throwable $e) { // @ignoreException - rethrown below, once every outcome was tried
                $this->logFailedRecord($outcome, $e);
                $thrown ??= $e;
            }
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        return \array_map(OrderSyncResultDTO::ofOutcome(...), $outcomes);
    }

    /**
     * @param list<OrderSyncOutcomeDTO> $outcomes
     *
     * @return list<OrderSyncOutcomeDTO>
     */
    private static function postedFirst(array $outcomes): array
    {
        $posted = \array_filter($outcomes, static fn(OrderSyncOutcomeDTO $outcome): bool => $outcome->detail instanceof OrderSyncPostedDTO);

        return [...$posted, ...\array_diff_key($outcomes, $posted)];
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function recordOne(OrderSyncChunkDTO $chunk, OrderSyncOutcomeDTO $outcome): void
    {
        if (!$this->write($chunk, $outcome)) {
            $this->logLostLease($outcome);
        }
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function write(OrderSyncChunkDTO $chunk, OrderSyncOutcomeDTO $outcome): bool
    {
        $claim = $outcome->order->claim;
        $detail = $outcome->detail;

        return match (true) {
            $detail instanceof OrderSyncPostedDTO => $this->orderSyncs->recordSynced($claim->orderSyncId, $claim->claimToken, $detail),
            $outcome->outcome === SyncOutcome::NotAttempted => $this->orderSyncs->release($claim->orderSyncId, $claim->claimToken),
            default => $this->orderSyncs->recordFailed($claim->orderSyncId, $claim->claimToken, self::failure($chunk, $outcome, $detail)),
        };
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

    private function logFailedRecord(OrderSyncOutcomeDTO $outcome, Throwable $e): void
    {
        $detail = $outcome->detail;
        // intentional: the class only, as the message and chain can hold customer data.
        $this->logger->error('QuickBooks order sync outcome could not be recorded', [
            'reference' => $outcome->order->row->reference,
            'outcome' => $outcome->outcome->value,
            'qbo_txn_id' => $detail instanceof OrderSyncPostedDTO ? $detail->qboTxnId : null,
            'exception' => $e::class,
        ]);
    }
}
