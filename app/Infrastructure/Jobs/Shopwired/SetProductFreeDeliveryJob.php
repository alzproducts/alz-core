<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\SetProductFreeDeliveryUseCase;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Exceptions\ProductIdentifierResolutionException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Update the free delivery custom field on a single ShopWired product.
 *
 * Processes one {@see SetFreeDeliveryCommand}. Designed for high-volume dispatch
 * on the `bulk` queue — rate limited to avoid exceeding API limits.
 *
 * @see SetProductFreeDeliveryUseCase For the single-item business logic
 */
final class SetProductFreeDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 15min: progressive delays for transient failures.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 900];

    public int $timeout = 60;

    public function __construct(
        public readonly SetFreeDeliveryCommand $command,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ServiceRateLimiter::shopwiredApiBulk(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     *
     * @throws ProductIdentifierResolutionException When identifier cannot be resolved (permanent — fails job)
     */
    public function handle(SetProductFreeDeliveryUseCase $useCase): void
    {
        try {
            $useCase->execute($this->command);
        } catch (ProductIdentifierResolutionException $e) {
            $this->fail($e);
        }
    }
}
