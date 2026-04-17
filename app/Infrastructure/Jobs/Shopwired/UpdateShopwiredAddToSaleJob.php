<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\SaleManagement\UseCases\AddProductToSaleUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\Skip;

/**
 * Adds a product to sale on ShopWired: sale category, sort order, and custom fields.
 *
 * Idempotent: skipped via Skip::when if the product is no longer on sale by execution time.
 * Delegates all business logic to AddProductToSaleUseCase.
 */
final class UpdateShopwiredAddToSaleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1200];

    public bool $failOnTimeout = true;

    public int $timeout = 60;

    public function __construct(
        public readonly IntId $productId,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Skip invokes immediately; DB exceptions bubble to queue retry)
            Skip::when(fn(): bool => !\app(ProductRepositoryInterface::class)->findDetailedProductView($this->productId)->hasAnySale),
            new HandleDatabaseExceptions(),
            ServiceRateLimiter::shopwiredApi(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(2)->toDateTimeImmutable();
    }

    /**
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws DatabaseOperationFailedException On DB query failure
     * @throws DuplicateRecordException On constraint violation
     */
    public function handle(AddProductToSaleUseCase $useCase): void
    {
        $useCase->execute($this->productId);
    }
}
