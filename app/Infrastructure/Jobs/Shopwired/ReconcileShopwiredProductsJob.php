<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\ReconcileProductsUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Asynchronously reconcile ShopWired products (remove orphans).
 *
 * Compares local product IDs against ShopWired API and removes any
 * that no longer exist in ShopWired.
 *
 * Usage:
 * - Reconciliation: ReconcileShopwiredProductsJob::dispatch() — monthly (first Sunday), after product sync
 *
 * Schedule: Runs 30 minutes after product sync to ensure local data is current.
 */
final class ReconcileShopwiredProductsJob extends AbstractJob implements ShouldBeUnique
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
     * Lightweight job (ID comparison only), so short delays.
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Job timeout in seconds.
     *
     * Set to 5 minutes for lightweight ID comparison.
     */
    public int $timeout = 300;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 600;

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'reconcile-shopwired-products';
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
     *
     * @throws DatabaseOperationFailedException
     */
    public function handle(ReconcileProductsUseCase $useCase): void
    {
        $useCase->execute();
    }

}
