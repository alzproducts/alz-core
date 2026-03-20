<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\SyncBrandsUseCase;
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
 * Asynchronously synchronize ShopWired brands to local database.
 *
 * Brands are a small, stable dataset (~30 items).
 *
 * Usage:
 * - SyncShopwiredBrandsJob::dispatch()
 *
 * Recommended scheduling: Daily (brands rarely change)
 */
final class SyncShopwiredBrandsJob implements ShouldBeUnique, ShouldQueue
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
        return 'sync-shopwired-brands';
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
    public function handle(SyncBrandsUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('ShopWired brand sync job starting');

        try {
            $result = $useCase->execute();

            $logger->info('ShopWired brand sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            $logger->warning('ShopWired brand sync service unavailable, will retry', [
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
            Log::error('ShopWired brand sync job failed permanently', $context);
        } else {
            Log::critical('ShopWired brand sync job failed permanently', $context);
        }
    }
}
