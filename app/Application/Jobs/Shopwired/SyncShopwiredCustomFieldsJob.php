<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\SyncCustomFieldsUseCase;
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
 * Asynchronously synchronize ShopWired custom field definitions to local database.
 *
 * Custom field definitions are schema/metadata describing what custom fields
 * exist for products, categories, customers, etc. This is a small, stable dataset
 * (~100-150 definitions) that changes infrequently.
 *
 * Usage:
 * - SyncShopwiredCustomFieldsJob::dispatch()
 *
 * Recommended scheduling: Weekly (definitions rarely change)
 */
final class SyncShopwiredCustomFieldsJob implements ShouldBeUnique, ShouldQueue
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
     * Expected runtime: ~10s (2-3 API calls for ~100-150 definitions).
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
        return 'sync-shopwired-custom-fields';
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
    public function handle(SyncCustomFieldsUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('ShopWired custom field definitions sync job starting');

        try {
            $result = $useCase->execute();

            $logger->info('ShopWired custom field definitions sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('ShopWired custom field sync service unavailable, will retry', [
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
            Log::error('ShopWired custom field definitions sync job failed permanently', $context);
        } else {
            Log::critical('ShopWired custom field definitions sync job failed permanently', $context);
        }
    }
}
