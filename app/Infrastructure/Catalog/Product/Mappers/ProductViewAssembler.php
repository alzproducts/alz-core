<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductInventory;
use App\Domain\Catalog\Product\ValueObjects\ProductLinks;
use App\Domain\Catalog\Product\ValueObjects\ProductStock;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Models\ProductVariationViewModel;
use App\Infrastructure\Catalog\Product\Models\ProductViewModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;
use App\Infrastructure\Shopwired\ShopwiredAdminUrlResolver;

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
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws DatabaseOperationFailedException When query fails
     * @throws DuplicateRecordException On constraint violation
     */
    public function toViewDomain(ProductViewModel $model, array $includes = []): ProductView
    {
        $typedCustomFields = $this->customFieldFactory->fromRawFields($model->custom_fields);
        $allVariations = $this->resolveVariations($model, $includes);
        $stockItem = $model->relationLoaded('stockItem') ? $model->stockItem : null;
        $defaultSupplier = self::resolveDefaultSupplier($model, $allVariations);

        return new ProductView(
            externalId: $model->external_id,
            sku: $model->sku,
            gtin: $model->gtin,
            title: $model->title,
            description: $model->description,
            slug: $model->slug,
            links: new ProductLinks(
                publicUrl: $model->url,
                editWebsiteUrl: ShopwiredAdminUrlResolver::productEditUrl($model->external_id),
            ),
            price: $model->price,
            costPrice: $model->cost_price,
            salePrice: $model->sale_price,
            rrp: $model->extraData?->rrp,
            effectivePrice: $model->effective_price,
            isOnSale: $model->is_on_sale,
            profitMargin: $model->profit_margin,
            isActive: $model->is_active,
            vatExclusive: $model->vat_exclusive,
            vatRelief: $model->vat_relief ?? false,
            metaTitle: $model->meta_title,
            metaDescription: $model->meta_description,
            categoryIds: $model->category_ids,
            mainCategoryIds: $model->main_category_ids,
            variations: \in_array(ProductInclude::Variations, $includes, true) ? $allVariations : null,
            images: self::buildImages($model->images),
            customFields: \in_array(ProductInclude::CustomFields, $includes, true) ? $typedCustomFields : [],
            filters: $this->resolveFilters($model, $includes),
            sortOrder: $model->sort_order,
            createdAt: $model->shopwired_created_at->toDateTimeImmutable(),
            updatedAt: $model->shopwired_updated_at->toDateTimeImmutable(),
            meta: new ProductViewMeta($allVariations, $defaultSupplier, $stockItem?->is_composite),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale($allVariations),
            parentAvailableStock: $model->available_stock,
            parentPhysicalStock: $model->physical_stock,
            saleSettings: self::resolveSaleSettings($model, $includes),
            freeDelivery: self::resolveFreeDelivery($typedCustomFields),
            suppliers: self::resolveSuppliers($model, $includes),
            inventory: self::resolveInventory($model, $includes),
            stock: self::resolveStock($model, $includes),
            defaultSupplier: $defaultSupplier,
            isComposite: $stockItem?->is_composite,
        );
    }

    /**
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
        if (! $model->relationLoaded('variations')) {
            return null;
        }

        $includeSuppliers = \in_array(ProductInclude::Suppliers, $includes, true);

        return \array_values($model->variations->map(
            fn(ProductVariationViewModel $m): ProductVariationView => $this->variationMapper->toViewDomain(
                model: $m,
                vatExclusive: $model->vat_exclusive,
                defaultSupplier: self::resolveVariationDefaultSupplier($m),
                suppliers: $includeSuppliers ? self::resolveVariationSuppliers($m) : null,
            ),
        )->all());
    }

    private static function resolveVariationDefaultSupplier(ProductVariationViewModel $model): ?ProductSupplier
    {
        if (! $model->relationLoaded('stockItem') || $model->stockItem === null) {
            return null;
        }

        return $model->stockItem->defaultSupplier()?->toProductSupplier();
    }

    /** @return list<ProductSupplier> */
    private static function resolveVariationSuppliers(ProductVariationViewModel $model): array
    {
        if (! $model->relationLoaded('stockItem') || $model->stockItem === null) {
            return [];
        }

        return \array_values($model->stockItem->suppliers
            ->sortByDesc('is_default')
            ->map(static fn(StockItemSupplierModel $s): ProductSupplier => $s->toProductSupplier())
            ->all());
    }

    /**
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
     * Resolve SaleSettings from ShopWired custom fields (source of truth).
     *
     * @param list<ProductInclude> $includes
     */
    private static function resolveSaleSettings(ProductViewModel $model, array $includes): ?SaleSettings
    {
        if (! \in_array(ProductInclude::SaleSettings, $includes, true)) {
            return null;
        }

        return SaleSettings::fromRawCustomFields($model->custom_fields);
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

    /** @param list<ProductVariationView>|null $variations */
    private static function resolveDefaultSupplier(ProductViewModel $model, ?array $variations): ?ProductSupplier
    {
        if ($model->relationLoaded('stockItem') && $model->stockItem !== null) {
            return $model->stockItem->defaultSupplier()?->toProductSupplier();
        }

        return $variations !== null ? ProductVariationView::commonDefaultSupplier($variations) : null;
    }

    /** @param list<AbstractCustomFieldValue> $typedCustomFields */
    private static function resolveFreeDelivery(array $typedCustomFields): ?FreeDeliveryType
    {
        $field = \array_find($typedCustomFields, static fn(AbstractCustomFieldValue $cf): bool => $cf->name() === 'free_delivery');

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
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductSupplier>|null
     */
    private static function resolveSuppliers(ProductViewModel $model, array $includes): ?array
    {
        if (! \in_array(ProductInclude::Suppliers, $includes, true)
            || ! $model->relationLoaded('stockItem')
            || $model->stockItem === null) {
            return null;
        }

        return \array_values($model->stockItem->suppliers
            ->sortByDesc('is_default')
            ->map(static fn(StockItemSupplierModel $s): ProductSupplier => $s->toProductSupplier())
            ->all());
    }

    /**
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
