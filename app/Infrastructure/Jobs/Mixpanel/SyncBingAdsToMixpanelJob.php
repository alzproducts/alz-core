<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;

/**
 * Asynchronously synchronize Bing Ads spend data to Mixpanel.
 *
 * Queues ad spend synchronization to prevent blocking HTTP responses.
 * Implements exponential backoff retry strategy for rate-limited API calls.
 *
 * Key difference from Google Ads: Bing Ads async reporting can take longer,
 * so this job has extended timeout to accommodate report generation polling.
 */
final class SyncBingAdsToMixpanelJob extends AbstractJob
{
    /**
     * Maximum number of attempts before giving up.
     *
     * Doubled from original 3 to allow middleware-consumed attempts.
     */
    public int $tries = 6;

    /**
     * Maximum number of unhandled exceptions before failing.
     *
     * Matches original $tries — middleware-handled exceptions (release/fail)
     * don't count, only rethrown exceptions decrement this.
     */
    public int $maxExceptions = 3;

    /**
     * Job timeout in seconds.
     *
     * Extended for Bing Ads async reporting (submit → poll → download).
     */
    public int $timeout = 600;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Job middleware pipeline.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            ServiceCircuitBreaker::mixpanel(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     */
    public function handle(SyncAdSpendUseCase $useCase): void
    {
        $useCase->execute(new DateRange($this->from, $this->to));
    }
}
