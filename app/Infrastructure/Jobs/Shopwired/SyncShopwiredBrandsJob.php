<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncBrandsUseCase;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use RuntimeException;

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
final class SyncShopwiredBrandsJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 6;

    public int $maxExceptions = 3;
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
     * @throws RuntimeException
     */
    public function handle(SyncBrandsUseCase $useCase): void
    {
        $useCase->execute();
    }

}
