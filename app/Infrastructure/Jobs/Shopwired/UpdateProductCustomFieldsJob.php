<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Catalog\UseCases\UpdateProductCustomFieldsUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
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
 * Generic per-product custom field update job.
 *
 * Reusable delivery mechanism for any custom field update dispatched from the
 * sync layer. Type safety is enforced at the dispatcher interface level — this
 * job accepts raw fields for reusability across different custom field syncs.
 */
final class UpdateProductCustomFieldsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    /** @var array<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 60;

    /**
     * @param  array<string, string|int|bool|list<string>|list<int>|null>  $customFields
     */
    public function __construct(
        public readonly IntId $productId,
        public readonly array $customFields,
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
     * @throws ValidationFailedException When fields fail validation
     * @throws ResourceNotAvailableException When product not found on API
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws InvalidApiResponseException When response parsing fails
     * @throws DatabaseOperationFailedException On DB query failure
     * @throws DuplicateRecordException On constraint violation
     */
    public function handle(UpdateProductCustomFieldsUseCase $useCase): void
    {
        $useCase->execute($this->productId, $this->customFields);
    }
}
