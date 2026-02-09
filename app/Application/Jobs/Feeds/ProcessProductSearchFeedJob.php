<?php

declare(strict_types=1);

namespace App\Application\Jobs\Feeds;

use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Application\Jobs\Enums\QueueName;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Process product search feed asynchronously.
 *
 * Fetches the source product feed, transforms XML (substitutes title with
 * display title), and uploads to cloud storage for site search consumption.
 * Scheduled daily at 1:00 AM UK time.
 */
final class ProcessProductSearchFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * 3 attempts: quick retries for transient issues + 1hr fallback for longer outages.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds (10 minutes).
     * Large feeds may take several minutes to process.
     */
    public int $timeout = 600;

    /**
     * Seconds the job can be uniquely locked.
     */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'process-product-search-feed';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When source feed unavailable (triggers retry)
     * @throws StorageOperationFailedException When S3 upload fails - will retry
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(ProcessProductSearchFeedUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('Product search feed processing job starting');

        try {
            $useCase->execute();

            $logger->info('Product search feed processing job completed');
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('Product search feed service unavailable, will retry', [
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempts' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (StorageOperationFailedException $e) {
            $logger->warning('Storage operation failed, will retry', [
                'message' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            // Let Laravel retry with backoff
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
            Log::error('Product search feed processing job failed permanently', $context);
        } else {
            Log::critical('Product search feed processing job failed permanently', $context);
        }
    }
}
