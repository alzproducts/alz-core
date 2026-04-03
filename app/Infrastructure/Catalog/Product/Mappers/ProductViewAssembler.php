<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductInventory;
use App\Domain\Catalog\Product\ValueObjects\ProductStock;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Product\Factories\ProductSupplierFactory;
use App\Infrastructure\Catalog\Product\Models\ProductVariationViewModel;
use App\Infrastructure\Catalog\Product\Models\ProductViewModel;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;

/**
 * Assembles ProductViewModel (Eloquent) into ProductView (Domain) for API responses.
 *
 * Always resolves typed custom fields via the factory (single raw JSONB access point).
 * The assembler's remaining job is conditional includes and custom field extraction —
 * the VO self-constructs domain types from primitives.
 */
final readonly class ProductViewAssembler
{
    public function __construct(
        private CustomFieldFactory $customFieldFactory,
        private ProductFilterFactory $filterFactory,
        private ProductVariationModelMapper $variationMapper,
        private SaleSettingsRepositoryInterface $saleSettingsRepo,
        private ProductSupplierFactory $supplierFactory,
    ) {}

    /**
     * Convert Eloquent view model to ProductView for API responses.
     *
     * Passes primitives directly — the VO self-constructs domain types.
     * Custom fields are always typed via the factory (single raw JSONB boundary).
     *
     * @param ProductViewModel $model The Eloquent view model (variations optionally eager-loaded)
     * @param list<ProductInclude> $includes Requested embeds
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function toViewDomain(ProductViewModel $model, array $includes = []): ProductView
    {
        $typedCustomFields = $this->customFieldFactory->fromRawFields($model->custom_fields);

        return new ProductView(
            externalId: $model->external_id,
            sku: $model->sku,
            title: $model->title,
            description: $model->description,
            slug: $model->slug,
            url: $model->url,
            price: $model->price,
            costPrice: $model->cost_price,
            salePrice: $model->sale_price,
            comparePrice: $model->compare_price,
            effectivePrice: $model->effective_price,
            isOnSale: $model->is_on_sale,
            profitMargin: $model->profit_margin,
            isActive: $model->is_active,
            vatExclusive: $model->vat_exclusive,
            vatRelief: $model->vat_relief ?? false,
            metaTitle: $model->meta_title,
            metaDescription: $model->meta_description,
            categoryIds: $model->category_ids,
            variations: $this->resolveVariations($model, $includes),
            images: self::buildImages($model->images),
            customFields: \in_array(ProductInclude::CustomFields, $includes, true) ? $typedCustomFields : [],
            filters: $this->resolveFilters($model, $includes),
            sortOrder: $model->sort_order,
            createdAt: $model->shopwired_created_at->toDateTimeImmutable(),
            updatedAt: $model->shopwired_updated_at->toDateTimeImmutable(),
            saleSettings: $this->resolveSaleSettings($model, $includes),
            freeDelivery: self::resolveFreeDelivery($typedCustomFields),
            suppliers: $this->resolveSuppliers($model, $includes),
            inventory: self::resolveInventory($model, $includes),
            stock: self::resolveStock($model, $includes),
        );
    }

    /**
     * Resolve variations to ProductVariationView when loaded and included.
     *
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductVariationView>|null
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveVariations(ProductViewModel $model, array $includes): ?array
    {
        if (! $model->relationLoaded('variations') || ! \in_array(ProductInclude::Variations, $includes, true)) {
            return null;
        }

        return \array_values($model->variations->map(
            fn(ProductVariationViewModel $m): ProductVariationView => $this->variationMapper->toViewDomain(
                model: $m,
                vatExclusive: $model->vat_exclusive,
            ),
        )->all());
    }

    /**
     * Conditionally type filters via factory.
     *
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductFilter>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveFilters(ProductViewModel $model, array $includes): array
    {
        if (! \in_array(ProductInclude::Filters, $includes, true)) {
            return [];
        }

        /** @var array<int|string, list<string>> $rawFilters */
        $rawFilters = $model->filters ?? [];

        return $this->filterFactory->fromRawFilters($rawFilters);
    }

    /**
     * Conditionally load sale settings from the repository.
     *
     * @param list<ProductInclude> $includes
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveSaleSettings(ProductViewModel $model, array $includes): ?SaleSettings
    {
        if (! \in_array(ProductInclude::SaleSettings, $includes, true)) {
            return null;
        }

        return $this->saleSettingsRepo->findByProduct(IntId::from($model->external_id));
    }

    /**
     * @param list<ProductInclude> $includes
     */
    private static function resolveInventory(ProductViewModel $model, array $includes): ?ProductInventory
    {
        if (! \in_array(ProductInclude::Inventory, $includes, true)
            || ! $model->relationLoaded('stockItem')
            || $model->stockItem === null) {
            return null;
        }

        return $model->stockItem->toProductInventory();
    }

    /**
     * @param list<ProductInclude> $includes
     */
    private static function resolveStock(ProductViewModel $model, array $includes): ?ProductStock
    {
        if (! \in_array(ProductInclude::Stock, $includes, true)
            || ! $model->relationLoaded('stockItem')
            || $model->stockItem === null) {
            return null;
        }

        return $model->stockItem->toProductStock();
    }

    /**
     * Find free delivery designation from typed custom fields.
     *
     * @param list<AbstractCustomFieldValue> $typedCustomFields
     */
    private static function resolveFreeDelivery(array $typedCustomFields): ?FreeDeliveryType
    {
        $field = self::findCustomFieldByName($typedCustomFields, 'free_delivery');

        if ($field === null) {
            return null;
        }

        $value = $field->rawValue();

        if (! \is_string($value) || $value === '') {
            return null;
        }

        return FreeDeliveryType::tryFrom($value);
    }

    /**
     * Find a typed custom field by name.
     *
     * @param list<AbstractCustomFieldValue> $customFields
     */
    private static function findCustomFieldByName(array $customFields, string $name): ?AbstractCustomFieldValue
    {
        return \array_find($customFields, static fn(AbstractCustomFieldValue $cf): bool => $cf->name() === $name);
    }

    /**
     * Conditionally load supplier data via the lazy-loaded factory.
     *
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductSupplier>|null
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveSuppliers(ProductViewModel $model, array $includes): ?array
    {
        if (! \in_array(ProductInclude::Suppliers, $includes, true)) {
            return null;
        }

        if ($model->sku === null || $model->sku === '') {
            return [];
        }

        return $this->supplierFactory->getByProductSku($model->sku);
    }

    /**
     * Convert JSONB images array to ProductImage objects.
     *
     * @param list<array{id: int, url: string, description: string|null, sort_order: int}> $images
     *
     * @return list<ProductImage>
     */
    private static function buildImages(array $images): array
    {
        return \array_map(
            static fn(array $img): ProductImage => ProductImage::fromArray($img),
            $images,
        );
    }
}
