<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
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
 * Removes a product from sale on ShopWired: sale category, sort order restore, and custom field cleanup.
 */
final readonly class RemoveProductFromSaleUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepo,
        private ProductFieldUpdateClientInterface $fieldUpdateClient,
        private ProductUpdateClientInterface $productUpdateClient,
        private SaleSettingsRepositoryInterface $saleSettingsRepo,
        private int $saleCategoryId,
    ) {}

    /**
     * @throws ResourceNotFoundException When product not found in DB
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
        $fieldUpdates = self::buildRemovalFieldUpdates($view, $this->saleCategoryId);

        if ($fieldUpdates !== []) {
            $this->fieldUpdateClient->update($productId->value, ...$fieldUpdates);
        }

        $this->productUpdateClient->updateCustomFields($productId->value, SaleCustomField::emptyValues());

        // Safety-net: ensure sale settings row is cleared (handles retries/edge cases)
        $this->saleSettingsRepo->delete($productId);
    }

    /**
     * Build field updates for removing a product from sale.
     *
     * Removes the sale category and restores the original sort order.
     *
     * @return list<ProductFieldUpdate>
     */
    private static function buildRemovalFieldUpdates(ProductView $view, int $saleCategoryId): array
    {
        $fieldUpdates = [];

        $saleCategory = IntId::from($saleCategoryId);
        if ($view->isInCategory($saleCategory)) {
            $filteredCategories = \array_values(\array_filter(
                \array_map(static fn(IntId $id): int => $id->value, $view->categoryIds),
                static fn(int $id): bool => $id !== $saleCategoryId,
            ));
            $fieldUpdates[] = ProductFieldUpdate::categories($filteredCategories);
        }

        $defaultSortOrder = $view->getCustomField(SaleCustomField::DefaultSortOrder->value)?->rawValue();
        if (\is_string($defaultSortOrder) && $defaultSortOrder !== '' && \is_numeric($defaultSortOrder)) {
            $fieldUpdates[] = ProductFieldUpdate::sortOrder((int) $defaultSortOrder);
        }

        return $fieldUpdates;
    }
}
