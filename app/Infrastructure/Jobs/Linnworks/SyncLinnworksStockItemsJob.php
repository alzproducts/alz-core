<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncAllStockItemsUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Infrastructure\Jobs\Enums\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
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
final class SyncLinnworksStockItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Low retry count since job runs every 15 min — next scheduled run is implicit retry.
     */
    public int $tries = 2;

    /**
     * Maximum number of unhandled exceptions to allow before failing.
     *
     * Matches $tries to ensure any exception type can trigger both retry attempts.
     */
    public int $maxExceptions = 2;

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

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 4200;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-stock-items';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When Linnworks API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncAllStockItemsUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('Linnworks stock item sync job starting');

        try {
            $result = $useCase->execute();

            $logger->info('Linnworks stock item sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('Linnworks stock item sync service unavailable, will retry', [
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
            Log::error('Linnworks stock item sync job failed permanently', $context);
        } else {
            Log::critical('Linnworks stock item sync job failed permanently', $context);
        }
    }
}
