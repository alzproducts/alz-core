<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SyncBrandsUseCase;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
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

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

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
            (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
                ->by('shopwired')
                ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    public function handle(SyncBrandsUseCase $useCase): void
    {
        $useCase->execute();
    }

}
