<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\SyncFilterGroupsUseCase;
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
 * Asynchronously synchronize ShopWired filter group definitions to local database.
 *
 * Filter group definitions describe the faceted navigation categories
 * (e.g., "Size", "Colour", "VAT Relief Eligible"). This is a small, stable
 * dataset (~10-20 groups) that changes infrequently.
 *
 * Usage:
 * - SyncShopwiredFilterGroupsJob::dispatch()
 *
 * Recommended scheduling: Daily (definitions rarely change)
 */
final class SyncShopwiredFilterGroupsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying (exponential backoff).
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Job timeout in seconds.
     *
     * Expected runtime: ~5s (1 API call for ~10-20 definitions).
     */
    public int $timeout = 60;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 120;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-shopwired-filter-groups';
    }

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncFilterGroupsUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('ShopWired filter group definitions sync job starting');

        try {
            $result = $useCase->execute();

            $logger->info('ShopWired filter group definitions sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('ShopWired filter group sync service unavailable, will retry', [
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
            Log::error('ShopWired filter group definitions sync job failed permanently', $context);
        } else {
            Log::critical('ShopWired filter group definitions sync job failed permanently', $context);
        }
    }
}
