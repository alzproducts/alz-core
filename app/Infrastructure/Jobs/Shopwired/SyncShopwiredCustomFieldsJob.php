<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncCustomFieldsUseCase;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

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
final class SyncShopwiredCustomFieldsJob extends AbstractJob implements ShouldBeUnique
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

    /**
     * Execute the job.
     */
    public function handle(SyncCustomFieldsUseCase $useCase): void
    {
        $useCase->execute();
    }

}
