<?php

declare(strict_types=1);

namespace App\Application\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncAllStockItemsUseCase;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously synchronize Linnworks stock items to local database.
 *
 * Full sync strategy: fetches all ~10k stock items with extended properties
 * and upserts them to the database. Designed for daily 5am execution.
 *
 * Usage:
 * - Full sync: SyncLinnworksStockItemsJob::dispatch() — daily at 5am, ~2-5 min
 */
final class SyncLinnworksStockItemsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts before giving up.
     *
     * Low retry count since job runs every 15 min — next scheduled run is implicit retry.
     */
    public int $tries = 2;

    /**
     * Seconds to wait before retrying.
     *
     * Single short retry; fail fast and let next schedule handle it.
     *
     * @var array<int>
     */
    public array $backoff = [60];

    /**
     * Job timeout in seconds.
     *
     * Set to 60 minutes to accommodate full sync of ~10k items.
     * Expected runtime: ~2-5 minutes under normal conditions.
     */
    public int $timeout = 3600;

    public function __construct()
    {
        $this->onQueue('low');
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When Linnworks API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncAllStockItemsUseCase $useCase): void
    {
        Log::info('Linnworks stock item sync job starting');

        try {
            $result = $useCase->execute();

            Log::info('Linnworks stock item sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            Log::warning('Linnworks stock item sync service unavailable, will retry', [
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempts' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (PermanentApiFailure $e) {
            Log::critical('Linnworks stock item sync permanent API failure, failing immediately', [
                'exception' => $e::class,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            Log::critical('Unexpected exception in Linnworks stock item sync - code update required', [
                'job' => self::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Linnworks stock item sync job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
