<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\ReviewsIo;

use App\Application\ReviewsIo\UseCases\UpdateShopwiredRatingsUseCase;
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
 * Push product ratings from local database to ShopWired custom fields.
 *
 * Stage 2 of the ratings sync pipeline. Reads aggregated ratings from
 * reviews_io.product_ratings and updates ShopWired products.
 */
final class UpdateShopwiredRatingsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 10;
    public int $maxExceptions = 5;
    public int $timeout = 900;
    public bool $failOnTimeout = true;
    public int $uniqueFor = 1200;

    /** @var array<int> */
    public array $backoff = [30, 60, 120, 240];

    public function uniqueId(): string
    {
        return 'update-shopwired-ratings';
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
                ->by('reviewsio')
                ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    public function handle(UpdateShopwiredRatingsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
