<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
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

/**
 * Adds a product to sale on ShopWired: sale category and sale custom fields.
 *
 * Reads SaleSettings fresh from DB at execution time to avoid stale data.
 * Fails permanently if settings are missing after applying the sale category
 * (partial success by design — product is in sale category but custom fields are defaults).
 */
final readonly class AddProductToSaleUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepo,
        private ProductFieldUpdateClientInterface $fieldUpdateClient,
        private ProductUpdateClientInterface $productUpdateClient,
        private SaleSettingsRepositoryInterface $saleSettingsRepo,
        private int $saleCategoryId,
    ) {}

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
    public function execute(IntId $productId): void
    {
        $view = $this->productRepo->findProductView(new ProductDetailQueryParams($productId));
        $saleSettings = $this->saleSettingsRepo->findByProduct($productId);

        $fieldUpdates = self::buildFieldUpdates($view, $this->saleCategoryId);
        if ($fieldUpdates !== []) {
            $this->fieldUpdateClient->update($productId->value, ...$fieldUpdates);
        }

        $this->productUpdateClient->updateCustomFields($productId->value, SaleSettings::toCustomFieldsArray($saleSettings));

        // Fail permanently if settings missing — sale category applied but custom fields are empty/default
        if ($saleSettings === null) {
            throw new ResourceNotFoundException('shopwired', 'ProductSaleSettings', $productId->value);
        }
    }

    /**
     * Build field updates: add the sale category if the product is not already a member.
     *
     * @return list<ProductFieldUpdate>
     */
    private static function buildFieldUpdates(ProductView $view, int $saleCategoryId): array
    {
        $saleCategory = IntId::from($saleCategoryId);
        if ($view->isInCategory($saleCategory)) {
            return [];
        }

        $currentCategoryIds = \array_map(static fn(IntId $id): int => $id->value, $view->categoryIds);

        return [ProductFieldUpdate::categories([...$currentCategoryIds, $saleCategoryId])];
    }
}
