<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs, then ends the job's run failed in one short update that never throws, so it can run while the worker is
 * being killed (ADR-0024).
 */
final readonly class HandleOrderSyncRunFailureUseCase
{
    public function __construct(
        private OrderSyncRunRepositoryInterface $runs,
        private LoggerInterface $logger,
    ) {}

    public function execute(OrderRangeRunDTO|OrderChunkRunDTO $run, Throwable $exception): void
    {
        if ($run instanceof OrderRangeRunDTO) {
            $this->logger->error('QuickBooks order range run failed', [
                'run_id' => $run->runId,
                'from' => $run->range->from->format('Y-m-d'),
                'to' => $run->range->to->format('Y-m-d'),
                'force_retry' => $run->forceRetry,
                'exception' => $exception::class,
            ]);
            $this->endFailed($run->runId);

            return;
        }

        $this->chunkFailed($run, $exception);
    }

    private function chunkFailed(OrderChunkRunDTO $run, Throwable $exception): void
    {
        $this->logger->error('QuickBooks order chunk failed', [
            'run_id' => $run->chunk->runId,
            'references' => $exception instanceof OrderChunkUnfinishedException ? $exception->references : $run->chunk->references,
            'attempt' => $run->attempt,
            'exception' => $exception::class,
        ]);
        $this->endFailed($run->chunk->runId);
    }

    private function endFailed(?string $runId): void
    {
        try {
            $this->runs->endJob($runId, OrderSyncRunCountsDTO::zero(), true);
        } catch (Throwable $e) { // @ignoreException - a failure handler must return, or the worker loses the job's own error
            $this->logger->error('Ending a failed QuickBooks order sync run failed', ['run_id' => $runId, 'exception' => $e::class]);
        }
    }
}
