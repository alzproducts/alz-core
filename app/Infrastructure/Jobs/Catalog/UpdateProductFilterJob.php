<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;

/**
 * Update one filter group's values on a single ShopWired product.
 *
 * Filter-agnostic: the caller supplies `$optionNo` and the string values. Used
 * by both the rating-filter sync and the VAT-relief sync — and any future
 * filter sync that wants per-product writes on the bulk queue with rate
 * limiting to avoid exceeding ShopWired API limits.
 */
final class UpdateProductFilterJob extends AbstractJob
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

    /**
     * @param list<string>|null $filterValues Filter values to set, or null to remove
     */
    public function __construct(
        public readonly IntId $productId,
        public readonly int $optionNo,
        public readonly ?array $filterValues,
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
     * @throws InvalidApiResponseException
     */
    public function handle(ProductUpdateClientInterface $updateClient): void
    {
        $updateClient->updateFilters(
            $this->productId->value,
            [$this->optionNo => $this->filterValues],
        );
    }
}
