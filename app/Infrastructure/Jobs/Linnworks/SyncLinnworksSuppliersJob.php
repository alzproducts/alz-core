<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Linnworks\UseCases\SyncSuppliersUseCase;
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
 * Asynchronously synchronize Linnworks supplier directory to local database.
 *
 * Full-replace strategy: fetches all suppliers, upserts, and reconciles deletions.
 * Designed for hourly execution (small dataset, ~seconds runtime).
 */
final class SyncLinnworksSuppliersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
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
    public array $backoff = [60];

    /**
     * Job timeout in seconds.
     *
     * Set to 2 minutes — supplier directory is a small dataset.
     */
    public int $timeout = 120;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 300;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-suppliers';
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
    public function handle(SyncSuppliersUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('Linnworks supplier directory sync job starting');

        try {
            $result = $useCase->execute();

            $logger->info('Linnworks supplier directory sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            $logger->warning('Linnworks supplier directory sync service unavailable, will retry', [
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
            Log::error('Linnworks supplier directory sync job failed permanently', $context);
        } else {
            Log::critical('Linnworks supplier directory sync job failed permanently', $context);
        }
    }
}
