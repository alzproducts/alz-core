<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\SyncOrdersRangeUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asynchronously synchronize ShopWired orders to local database (date-range based).
 *
 * Queues order synchronization from ShopWired API to PostgreSQL.
 * Implements exponential backoff retry strategy for API rate limits.
 *
 * Typical usage: hourly schedule with 2-hour overlap window.
 * For historical backfills: dispatch multiple jobs with smaller date ranges.
 *
 * @see SyncShopwiredOrdersJob For generator-based full/quick/micro sync
 */
final class SyncShopwiredOrdersRangeJob implements ShouldQueue
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
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds.
     *
     * Set to 2.5 hours to accommodate large date-range syncs with buffer.
     */
    public int $timeout = 9000;

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncOrdersRangeUseCase $useCase, LoggerInterface $logger): void
    {
        $fromString = $this->from->format('Y-m-d H:i:s');
        $toString = $this->to->format('Y-m-d H:i:s');

        $logger->info('ShopWired order sync job starting', [
            'from' => $fromString,
            'to' => $toString,
        ]);

        try {
            $result = $useCase->execute($this->from, $this->to);

            $logger->info('ShopWired order sync job completed', [
                'from' => $fromString,
                'to' => $toString,
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('ShopWired order sync service unavailable, will retry', [
                'from' => $fromString,
                'to' => $toString,
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
            'from' => $this->from->format('Y-m-d H:i:s'),
            'to' => $this->to->format('Y-m-d H:i:s'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('ShopWired order sync job failed permanently', $context);
        } else {
            Log::critical('ShopWired order sync job failed permanently', $context);
        }
    }

    /**
     * Create a job for hourly scheduled sync with 2-hour overlap.
     *
     * The overlap ensures orders modified near sync boundaries are captured.
     */
    public static function hourly(): self
    {
        $to = new DateTimeImmutable('now');
        $from = $to->modify('-2 hours');

        return new self($from, $to);
    }
}
