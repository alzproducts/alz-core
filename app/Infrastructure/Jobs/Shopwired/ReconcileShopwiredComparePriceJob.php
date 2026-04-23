<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\PricingUpdate\UseCases\ReconcileShopwiredComparePriceUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reconcile ShopWired comparePrice from per-SKU RRP data.
 *
 * Dispatched after retail price DB writes succeed.
 * ShouldBeUnique per product ID to avoid redundant API calls.
 */
final class ReconcileShopwiredComparePriceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public bool $failOnTimeout = true;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly IntId $productId,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    public function uniqueId(): string
    {
        return 'reconcile-shopwired-compare-price-' . $this->productId->value;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new HandleDatabaseExceptions(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(1)->toDateTimeImmutable();
    }

    /**
     * @throws RecordNotFoundException When product row not found in local DB
     * @throws ResourceNotFoundException When product not found on ShopWired API
     * @throws ResourceNotAvailableException When ShopWired product not found for update
     * @throws InvalidApiRequestException When API request invalid
     * @throws AuthenticationExpiredException When credentials expired
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws DatabaseOperationFailedException When product lookup fails
     * @throws DuplicateRecordException On constraint violation
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws MissingRequiredDataException When custom field definitions empty
     */
    public function handle(ReconcileShopwiredComparePriceUseCase $useCase): void
    {
        $useCase->execute($this->productId);
    }
}
