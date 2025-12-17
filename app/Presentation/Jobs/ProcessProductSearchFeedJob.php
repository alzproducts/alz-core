<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\StorageOperationFailedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process product search feed asynchronously.
 *
 * Fetches the source product feed, transforms XML (substitutes title with
 * display title), and uploads to cloud storage for site search consumption.
 * Scheduled daily at 1:00 AM UK time.
 */
final class ProcessProductSearchFeedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum number of attempts before giving up.
     * Feed processing can fail due to source unavailability or S3 issues.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying (1min, 5min, 15min).
     * Allows transient issues (rate limits, network) to resolve.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Job timeout in seconds (10 minutes).
     * Large feeds may take several minutes to process.
     */
    public int $timeout = 600;

    /**
     * Execute the job.
     *
     * @throws ExternalServiceUnavailableException When source feed unavailable - will retry
     * @throws StorageOperationFailedException When S3 upload fails - will retry
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(ProcessProductSearchFeedUseCase $useCase): void
    {
        Log::info('Product search feed processing job starting');

        try {
            $useCase->execute();

            Log::info('Product search feed processing job completed');
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('Source feed unavailable, will retry', [
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter ?? 'using backoff',
                'attempts' => $this->attempts(),
            ]);

            // Use server's retry delay if provided, otherwise let Laravel use backoff array
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (StorageOperationFailedException $e) {
            Log::warning('Storage operation failed, will retry', [
                'message' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            // Let Laravel retry with backoff
            throw $e;
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            // Fail immediately - don't waste retries on unknown errors
            Log::critical('Unexpected exception in product feed job - code update required', [
                'job' => static::class,
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
        Log::critical('Product search feed processing job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
