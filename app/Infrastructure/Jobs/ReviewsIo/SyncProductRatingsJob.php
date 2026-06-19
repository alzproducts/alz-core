<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\ReviewsIo;

use App\Application\ReviewsIo\UseCases\SyncProductRatingsUseCase;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use RuntimeException;

/**
 * Sync product ratings from Reviews.io API to local database.
 *
 * Stage 1 of the ratings sync pipeline. Fetches ratings for all SKUs
 * and stores them in reviews_io.product_ratings.
 */
final class SyncProductRatingsJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 10;
    public int $maxExceptions = 5;
    public int $timeout = 900;
    public int $uniqueFor = 1200;

    /** @var array<int> */
    public array $backoff = [30, 60, 120, 240];

    public function uniqueId(): string
    {
        return 'sync-product-ratings';
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
            ServiceCircuitBreaker::reviewsio(),
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
    public function handle(SyncProductRatingsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
