<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
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
 * Asynchronously synchronize product enrichment lookup table to Mixpanel.
 *
 * Syncs product metadata (category, supplier) enabling Mixpanel reports to
 * enrich any event with sku property (e.g., add to cart, purchase).
 *
 * Implements exponential backoff for rate limit and transient error handling.
 */
final class SyncProductLookupTableJob implements ShouldBeUnique, ShouldQueue
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
     * Job timeout in seconds.
     *
     * 5 minutes: generous buffer for ~10k SKU query + CSV generation + upload.
     * Expected actual time: < 30 seconds.
     */
    public int $timeout = 300;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 600;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-product-lookup-table';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Execute the job: synchronize product enrichment lookup table.
     *
     * @throws TransientApiFailure When Mixpanel API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncLookupTableUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('Product lookup table sync job starting');

        try {
            $useCase->execute();

            $logger->info('Product lookup table sync job completed successfully');
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('Product lookup table sync service unavailable, will retry', [
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
     * Handle job failure with logging.
     */
    public function failed(Throwable $exception): void
    {
        $context = [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('Product lookup table sync job failed', $context);
        } else {
            Log::critical('Product lookup table sync job failed', $context);
        }
    }
}
