<?php

declare(strict_types=1);

namespace App\Application\Jobs\Linnworks;

use App\Application\Jobs\Enums\QueueName;
use App\Application\Linnworks\UseCases\SyncLinnworksCursorUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cursor-based incremental order sync from Linnworks.
 *
 * Runs every minute. Fetches orders modified since the last cursor position.
 * Lightweight — typically processes only a few orders per run.
 */
final class SyncLinnworksOrdersByCursorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Low retry count since job runs every minute — next scheduled run is implicit retry.
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
     */
    public int $timeout = 90;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-orders-cursor';
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When Linnworks API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(SyncLinnworksCursorUseCase $useCase, LoggerInterface $logger): void
    {
        try {
            $result = $useCase->execute();

            $logger->info('Linnworks cursor order sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            $logger->warning('Linnworks cursor order sync: service unavailable, will retry', [
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
            Log::error('Linnworks cursor order sync job failed permanently', $context);
        } else {
            Log::critical('Linnworks cursor order sync job failed permanently', $context);
        }
    }
}
