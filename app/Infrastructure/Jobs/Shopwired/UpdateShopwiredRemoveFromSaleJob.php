<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
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

/**
 * Removes a product from sale on ShopWired: sale category, sort order restore, and custom field cleanup.
 */
final class UpdateShopwiredRemoveFromSaleJob implements ShouldQueue
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
        public readonly int $saleCategoryId,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
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
     * @throws ResourceNotFoundException When product not found in DB
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws DatabaseOperationFailedException On DB query failure
     * @throws ResourceNotAvailableException When product not found on API
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws InvalidApiResponseException When response parsing fails
     */
    public function handle(
        ProductRepositoryInterface $productRepo,
        ProductFieldUpdateClientInterface $fieldUpdateClient,
        ProductUpdateClientInterface $productUpdateClient,
    ): void {
        $productId = $this->productId->value;
        $product = $productRepo->getProduct($this->productId);

        // 1. Remove category + restore sort order in a single PUT (where applicable)
        $fieldUpdates = [];

        $saleCategoryId = $this->saleCategoryId;
        if ($product->isInCategory($saleCategoryId)) {
            $filteredCategories = \array_values(\array_filter(
                $product->categoryIds,
                static fn(int $id): bool => $id !== $saleCategoryId,
            ));
            $fieldUpdates[] = ProductFieldUpdate::categories($filteredCategories);
        }

        $defaultSortOrder = $product->rawCustomFields[SaleCustomField::DefaultSortOrder->value] ?? null;
        if (\is_string($defaultSortOrder) && $defaultSortOrder !== '' && \is_numeric($defaultSortOrder)) {
            $fieldUpdates[] = ProductFieldUpdate::sortOrder((int) $defaultSortOrder);
        }

        if ($fieldUpdates !== []) {
            $fieldUpdateClient->update($productId, ...$fieldUpdates);
        }

        // 2. Clear all sale custom fields
        $productUpdateClient->updateCustomFields($productId, [
            SaleCustomField::DateStart->value => '',
            SaleCustomField::DateEnd->value => '',
            SaleCustomField::Reason->value => '',
            SaleCustomField::Comments->value => '',
            SaleCustomField::EndsStock->value => '',
            SaleCustomField::DefaultSortOrder->value => '',
        ]);
    }
}
