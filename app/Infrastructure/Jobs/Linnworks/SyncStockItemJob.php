<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncStockItemUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Jobs\Enums\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue wrapper for syncing a single stock item from Linnworks.
 *
 * Dispatched by SyncStockItemWithCursorUseCase for each recently-modified item.
 * Uniqueness scoped per stockItemId to prevent concurrent syncs of the same item.
 */
final class SyncStockItemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 3;

    /**
     * Maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Seconds to wait before retrying.
     *
     * Progressive backoff: 10s, 60s, 10min. Final attempt has a long
     * delay because failure means the item won't update until the daily
     * full sync (up to 24 hours).
     *
     * @var array<int>
     */
    public array $backoff = [10, 60, 600];

    /**
     * Job timeout in seconds.
     *
     * Single item fetch + save should complete well within 30s.
     */
    public int $timeout = 30;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly Guid $stockItemId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-stock-item-' . $this->stockItemId->value;
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When Linnworks API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(SyncStockItemUseCase $useCase): void
    {
        try {
            $useCase->execute($this->stockItemId);
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
            'stock_item_id' => $this->stockItemId->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('Stock item sync job failed permanently', $context);
        } else {
            Log::critical('Stock item sync job failed permanently', $context);
        }
    }
}
