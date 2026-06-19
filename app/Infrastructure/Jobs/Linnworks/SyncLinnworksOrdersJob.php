<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\Enums\OrderSyncTier;
use App\Application\Linnworks\UseCases\SyncLinnworksOrdersUseCase;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Tier-based order sync from Linnworks (hourly/daily/weekly/full).
 *
 * Each tier uses a different lookback window but shares the same
 * SyncLinnworksOrdersUseCase. Wider tiers act as safety nets.
 */
final class SyncLinnworksOrdersJob extends AbstractJob implements ShouldBeUnique
{
    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 4;

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
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     *
     * IMPORTANT: fromDate() called here in handle(), not in constructor (Octane safety).
     */
    public function handle(SyncLinnworksOrdersUseCase $useCase): void
    {
        $useCase->execute($this->tier->fromDate());
    }
}
