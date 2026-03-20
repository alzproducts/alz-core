<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Linnworks\Enums\OrderSyncTier;
use App\Application\Linnworks\UseCases\SyncLinnworksOrdersUseCase;
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
 * Tier-based order sync from Linnworks (hourly/daily/weekly/full).
 *
 * Each tier uses a different lookback window but shares the same
 * SyncLinnworksOrdersUseCase. Wider tiers act as safety nets.
 */
final class SyncLinnworksOrdersJob implements ShouldBeUnique, ShouldQueue
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
     * Long timeout for full sync (90 days lookback).
     */
    public int $timeout = 3600;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 4200;

    public function __construct(
        public readonly OrderSyncTier $tier,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Get the unique ID for the job (includes tier for per-tier uniqueness).
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-orders-' . $this->tier->value;
    }

    /**
     * Execute the job.
     *
     * IMPORTANT: fromDate() called here in handle(), not in constructor (Octane safety).
     *
     * @throws TransientApiFailure When Linnworks API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(SyncLinnworksOrdersUseCase $useCase, LoggerInterface $logger): void
    {
        $fromDate = $this->tier->fromDate();

        $logger->info('Linnworks order sync job starting', [
            'tier' => $this->tier->value,
            'from_date' => $fromDate->format('Y-m-d H:i:s'),
        ]);

        try {
            $result = $useCase->execute($fromDate);

            $logger->info('Linnworks order sync job completed', [
                'tier' => $this->tier->value,
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            $logger->warning('Linnworks order sync: service unavailable, will retry', [
                'tier' => $this->tier->value,
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
            'tier' => $this->tier->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('Linnworks order sync job failed permanently', $context);
        } else {
            Log::critical('Linnworks order sync job failed permanently', $context);
        }
    }
}
