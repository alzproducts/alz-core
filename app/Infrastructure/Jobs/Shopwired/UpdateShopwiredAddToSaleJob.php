<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
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
 * Adds a product to sale on ShopWired: sale category, sort order, and custom fields.
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

    private const int SALE_SORT_ORDER = 3;

    public function __construct(
        public readonly IntId $productId,
        public readonly SaleSettings $saleSettings,
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

        // 1. Update category + sort order in a single PUT
        $fieldUpdates = [ProductFieldUpdate::sortOrder(self::SALE_SORT_ORDER)];

        if (! $product->isInCategory($this->saleCategoryId)) {
            $fieldUpdates[] = ProductFieldUpdate::categories([...$product->categoryIds, $this->saleCategoryId]);
        }

        $fieldUpdateClient->update($productId, ...$fieldUpdates);

        // 2. Write sale metadata custom fields
        $productUpdateClient->updateCustomFields($productId, [
            SaleCustomField::DateStart->value => $this->saleSettings->saleStartDate?->format('Y-m-d') ?? \now()->format('Y-m-d'),
            SaleCustomField::DefaultSortOrder->value => (string) ($product->sortOrder ?? ''),
            SaleCustomField::Reason->value => $this->saleSettings->saleReason,
            SaleCustomField::Comments->value => $this->saleSettings->saleComments ?? '',
            SaleCustomField::DateEnd->value => $this->saleSettings->saleEndDate?->format('Y-m-d') ?? '',
            SaleCustomField::EndsStock->value => $this->saleSettings->saleEndsStock !== null
                ? (string) $this->saleSettings->saleEndsStock
                : '',
        ]);
    }
}
