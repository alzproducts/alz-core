<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
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
 * Reads SaleSettings fresh from DB at execution time to avoid stale serialized payload data.
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
        public readonly int $saleCategoryId,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Skip invokes immediately; DB exceptions bubble to queue retry)
            Skip::when(fn(): bool => !\app(ProductRepositoryInterface::class)->getProduct($this->productId)->isOnSale()),
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
     * @throws ResourceNotFoundException When product not found in DB or sale settings missing (permanent)
     * @throws DuplicateRecordException On sale settings DB constraint violation
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
        SaleSettingsRepositoryInterface $saleSettingsRepo,
    ): void {
        $productId = $this->productId->value;
        $product = $productRepo->getProduct($this->productId);

        // Attempt to read persisted settings — do not fail yet if missing.
        // We apply what we can and throw a permanent error at the very end.
        $saleSettings = $saleSettingsRepo->findByProduct($this->productId);

        // 1. Update category + sort order in a single PUT
        $fieldUpdates = [ProductFieldUpdate::sortOrder(self::SALE_SORT_ORDER)];

        if (! $product->isInCategory($this->saleCategoryId)) {
            $fieldUpdates[] = ProductFieldUpdate::categories([...$product->categoryIds, $this->saleCategoryId]);
        }

        $fieldUpdateClient->update($productId, ...$fieldUpdates);

        // 2. Write sale metadata custom fields (best-effort if settings missing)
        $productUpdateClient->updateCustomFields($productId, self::buildCustomFieldsArray($saleSettings, $product->sortOrder));

        // 3. If settings were missing, fail permanently now — category + sort order were applied
        //    but custom fields contain empty/default values only.
        if ($saleSettings === null) {
            throw new ResourceNotFoundException(
                serviceName: 'shopwired',
                resourceType: 'ProductSaleSettings',
                resourceId: $productId,
            );
        }
    }

    /**
     * Build the custom fields payload for the sale update.
     *
     * When $settings is null (settings row missing), writes empty/default values so the
     * custom fields block still exists on the product. The caller is expected to fail
     * permanently after this to signal incomplete data.
     *
     * @return array<string, string>
     */
    private static function buildCustomFieldsArray(?SaleSettings $settings, ?int $defaultSortOrder): array
    {
        return [
            SaleCustomField::DateStart->value => $settings?->saleStartDate?->format('Y-m-d') ?? \now()->format('Y-m-d'),
            SaleCustomField::DefaultSortOrder->value => (string) ($defaultSortOrder ?? ''),
            SaleCustomField::Reason->value => $settings !== null ? $settings->saleReason : '',
            SaleCustomField::Comments->value => $settings !== null ? ($settings->saleComments ?? '') : '',
            SaleCustomField::DateEnd->value => $settings?->saleEndDate?->format('Y-m-d') ?? '',
            SaleCustomField::EndsStock->value => $settings?->saleEndsStock !== null
                ? (string) $settings->saleEndsStock
                : '',
        ];
    }
}
