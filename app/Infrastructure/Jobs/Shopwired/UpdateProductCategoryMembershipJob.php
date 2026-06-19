<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\CategoryMembership\UseCases\UpdateProductCategoryMembershipUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;

/**
 * Delivery mechanism for a single-product category-membership update on ShopWired.
 *
 * All business logic — including the idempotency / duplicate check — lives in
 * UpdateProductCategoryMembershipUseCase. This job only provides queue mechanics
 * (rate limiting, retries, circuit breaking).
 */
final class UpdateProductCategoryMembershipJob extends AbstractJob
{
    public int $tries = 6;

    public int $maxExceptions = 3;
    /** @var array<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 60;

    /**
     * @param  list<IntId>  $addCategoryIds
     * @param  list<IntId>  $removeCategoryIds
     */
    public function __construct(
        public readonly IntId $productId,
        public readonly array $addCategoryIds,
        public readonly array $removeCategoryIds,
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
     * @throws RecordNotFoundException When product row not found in local DB
     * @throws ResourceNotFoundException When product not found on ShopWired API
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws ResourceNotAvailableException When product not found on API
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws DatabaseOperationFailedException On DB query failure
     * @throws DuplicateRecordException On DB constraint violation
     */
    public function handle(UpdateProductCategoryMembershipUseCase $useCase): void
    {
        $useCase->execute($this->productId, $this->addCategoryIds, $this->removeCategoryIds);
    }
}
