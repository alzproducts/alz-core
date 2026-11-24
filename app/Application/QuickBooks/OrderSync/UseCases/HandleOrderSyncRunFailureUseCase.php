<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Only logs, so it can run while the worker is being killed (ADR-0024).
 */
final readonly class HandleOrderSyncRunFailureUseCase
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function execute(OrderRangeRunDTO|OrderChunkRunDTO $run, Throwable $exception): void
    {
        if ($run instanceof OrderRangeRunDTO) {
            $this->logger->error('QuickBooks order range run failed', [
                'run_id' => $run->runId,
                'from' => $run->from->format('Y-m-d'),
                'to' => $run->to->format('Y-m-d'),
                'force_retry' => $run->forceRetry,
                'exception' => $exception::class,
            ]);

            return;
        }

        $this->logger->error('QuickBooks order chunk failed', [
            'run_id' => $run->chunk->runId,
            'references' => $exception instanceof OrderChunkUnfinishedException ? $exception->references : $run->chunk->references,
            'attempt' => $run->attempt,
            'exception' => $exception::class,
        ]);
    }
}
