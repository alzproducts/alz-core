<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\SyncCategoriesUseCase;
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
 * Asynchronously synchronize ShopWired categories to local database.
 *
 * Categories are a small, stable dataset (~50 items).
 *
 * Usage:
 * - SyncShopwiredCategoriesJob::dispatch()
 *
 * Recommended scheduling: Daily (categories rarely change)
 */
final class SyncShopwiredCategoriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [30, 60, 120];

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return 'sync-shopwired-categories';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(SyncCategoriesUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('ShopWired category sync job starting');

        try {
            $result = $useCase->execute();

            $logger->info('ShopWired category sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            $logger->warning('ShopWired category sync service unavailable, will retry', [
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

    public function failed(Throwable $exception): void
    {
        $context = [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('ShopWired category sync job failed permanently', $context);
        } else {
            Log::critical('ShopWired category sync job failed permanently', $context);
        }
    }
}
