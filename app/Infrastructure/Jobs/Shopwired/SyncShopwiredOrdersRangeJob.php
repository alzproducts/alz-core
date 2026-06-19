<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncOrdersRangeUseCase;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;

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
final class SyncShopwiredOrdersRangeJob extends AbstractJob
{
    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 6;

    /**
     * Maximum exceptions allowed before failing.
     */
    public int $maxExceptions = 3;
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

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            ServiceRateLimiter::shopwiredApi(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    public function handle(SyncOrdersRangeUseCase $useCase): void
    {
        $useCase->execute($this->from, $this->to);
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
