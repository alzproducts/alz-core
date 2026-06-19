<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;

/**
 * Update the sort_order field on a single ShopWired product.
 *
 * Dispatched by SyncProductSortOrdersJob — one job per product with a
 * sort order difference. Runs on the bulk queue with rate limiting and
 * circuit breaker to avoid exceeding ShopWired API limits.
 */
final class UpdateProductSortOrderJob extends AbstractJob
{
    public int $tries = 6;

    public int $maxExceptions = 3;
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
        public readonly IntId $productId,
        public readonly int $sortOrder,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
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
     * @throws ResourceNotAvailableException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(ProductFieldUpdateClientInterface $client): void
    {
        $client->update($this->productId->value, ProductFieldUpdate::sortOrder($this->sortOrder));
    }
}
