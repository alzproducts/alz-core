<?php

declare(strict_types=1);

namespace App\Application\Jobs\Linnworks;

use App\Application\Jobs\Enums\QueueName;
use App\Application\Linnworks\UseCases\SyncStockItemWithCursorUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled orchestrator for cursor-based stock item sync.
 *
 * Runs every 5 minutes, detects recently-modified stock items via SQL,
 * and dispatches individual SyncStockItemJob per modified item.
 */
final class SyncStockItemsWithCursorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Low retry count since job runs every 5 min — next scheduled run is implicit retry.
     */
    public int $tries = 2;

    /**
     * Maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * Seconds to wait before retrying.
     *
     * @var array<int>
     */
    public array $backoff = [30];

    /**
     * Job timeout in seconds.
     *
     * The job only queries for modified IDs and dispatches jobs — no heavy work.
     */
    public int $timeout = 60;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-stock-items-with-cursor';
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When Linnworks API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(SyncStockItemWithCursorUseCase $useCase): void
    {
        try {
            $useCase->execute();
        } catch (TransientApiFailure $e) {
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (PermanentApiFailure $e) {
            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $context = [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('Stock item cursor sync job failed permanently', $context);
        } else {
            Log::critical('Stock item cursor sync job failed permanently', $context);
        }
    }
}
