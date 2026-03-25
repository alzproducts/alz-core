<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;
use App\Infrastructure\Catalog\Product\Factories\ProductCostPriceFactory;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;
use App\Infrastructure\Shopwired\Factories\ProductCustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;

/**
 * Maps between ProductModel (Eloquent) and Product/ProductView (Domain).
 *
 * Two mapping paths:
 * - `toDomain()`: Full conversion with custom fields/filters (internal use, returns Product)
 * - `toViewDomain()`: API projection with domain types, Linnworks cost prices, and conditional includes (returns ProductView)
 *
 * **Write path**: Use static `toModelAttributes()` for persistence.
 */
final class ProductModelMapper
{
    public function __construct(
        private readonly ProductCustomFieldFactory $customFieldFactory,
        private readonly ProductFilterFactory $filterFactory,
        private readonly ProductCostPriceFactory $costPriceFactory,
        private readonly ProductVariationModelMapper $variationMapper,
    ) {}

    /**
     * Convert Eloquent model with loaded relations to Domain Product.
     *
     * Requires 'variations' relation to be eager-loaded.
     * Custom fields are typed using ProductCustomFieldFactory.
     *
     * @param ProductModel $model The Eloquent model with loaded relations
     *
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function toDomain(ProductModel $model): Product
    {
        /** @var list<ProductVariation> $variations */
        $variations = $model->variations->map(
            static fn(ProductVariationModel $m): ProductVariation => ProductVariationModelMapper::toDomain($m),
        )->all();

        /** @var array<string, mixed> $rawCustomFields */
        $rawCustomFields = $model->custom_fields;

        /** @var array<int|string, list<string>> $rawFilters */
        $rawFilters = $model->filters ?? [];

        return new Product(
            id: $model->external_id,
            sku: $model->sku,
            gtin: $model->gtin !== null ? Gtin::fromTrusted($model->gtin) : null,
            title: $model->title,
            description: $model->description,
            slug: $model->slug,
            url: $model->url,
            price: $model->price,
            costPrice: $model->cost_price,
            salePrice: $model->sale_price,
            comparePrice: $model->compare_price,
            stock: $model->stock ?? 0,
            isActive: $model->is_active,
            vatExclusive: $model->vat_exclusive,
            vatRelief: $model->vat_relief ?? false,
            weight: $model->weight,
            metaTitle: $model->meta_title,
            metaDescription: $model->meta_description,
            categoryIds: $model->category_ids,
            variations: $variations,
            images: self::buildImages($model->images),
            rawCustomFields: $rawCustomFields,
            customFields: $this->customFieldFactory->fromRawFields($rawCustomFields),
            rawFilters: $rawFilters,
            filters: $this->filterFactory->fromRawFilters($rawFilters),
            sortOrder: $model->sort_order,
            createdAt: $model->shopwired_created_at->toDateTimeImmutable(),
            updatedAt: $model->shopwired_updated_at->toDateTimeImmutable(),
        );
    }

    /**
     * Convert Eloquent model to ProductView for API responses.
     *
     * Always enriches cost prices from Linnworks (single lazy-loaded DB call per Octane lifecycle).
     * Conditionally loads variations, custom fields, and filters based on includes.
     *
     * @param ProductModel $model The Eloquent model (variations optionally eager-loaded)
     * @param list<string> $includes Requested embed names (controls variations, custom_fields, filters, description, category_ids)
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function toViewDomain(ProductModel $model, array $includes = []): ProductView
    {
        $taxType = $model->vat_exclusive ? TaxType::Exclusive : TaxType::Inclusive;

        return new ProductView(
            id: IntId::from($model->external_id),
            sku: $model->sku !== null ? Sku::fromTrusted($model->sku) : null,
            gtin: $model->gtin !== null ? Gtin::fromTrusted($model->gtin) : null,
            title: $model->title,
            description: $model->description,
            slug: $model->slug,
            url: $model->url,
            price: Money::fromTaxType($model->price, $taxType),
            costPrice: Money::nonZeroOrNull($this->getLinnworksCostPrice($model->sku), $taxType),
            salePrice: Money::nonZeroOrNull($model->sale_price, $taxType),
            comparePrice: Money::nonZeroOrNull($model->compare_price, $taxType),
            stock: $model->stock ?? 0,
            isActive: $model->is_active,
            vatExclusive: $model->vat_exclusive,
            vatRelief: $model->vat_relief ?? false,
            weight: $model->weight !== null ? Weight::kilogram($model->weight) : null,
            metaTitle: $model->meta_title,
            metaDescription: $model->meta_description,
            categoryIds: \array_map(static fn(int $id): IntId => IntId::from($id), $model->category_ids),
            variations: $this->resolveVariations($model, $includes),
            images: self::buildImages($model->images),
            customFields: $this->resolveCustomFields($model, $includes),
            filters: $this->resolveFilters($model, $includes),
            sortOrder: $model->sort_order,
            createdAt: $model->shopwired_created_at->toDateTimeImmutable(),
            updatedAt: $model->shopwired_updated_at->toDateTimeImmutable(),
        );
    }

    /**
     * Convert Domain Product to Eloquent model attributes.
     *
     * Does not include primary key or relationship IDs (handled by repository).
     * Variations are synced separately.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(Product $product): array
    {
        return [
            ...self::coreAttributes($product),
            ...self::embedAttributes($product),
        ];
    }

    /**
     * Convert Domain Product to model attributes for webhook partial save.
     *
     * Only includes embed-dependent columns that were actually present
     * in the webhook payload. Core scalar fields are always included.
     *
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @return array<string, mixed>
     */
    public static function toWebhookAttributes(Product $product, array $presentEmbeds): array
    {
        $attributes = self::coreAttributes($product);

        if (\in_array('vat_relief', $presentEmbeds, true)) {
            $attributes['vat_relief'] = $product->vatRelief;
        }

        if (\in_array('categories', $presentEmbeds, true)) {
            $attributes['category_ids'] = $product->categoryIds;
        }

        if (\in_array('images', $presentEmbeds, true)) {
            $attributes['images'] = \array_map(
                static fn(ProductImage $img): array => $img->toArray(),
                $product->images,
            );
        }

        if (\in_array('custom_fields', $presentEmbeds, true)) {
            $attributes['custom_fields'] = $product->rawCustomFields;
        }

        if (\in_array('filters', $presentEmbeds, true)) {
            $attributes['filters'] = $product->rawFilters;
        }

        return $attributes;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Core scalar attributes shared by full and webhook save paths.
     *
     * @return array<string, mixed>
     */
    private static function coreAttributes(Product $product): array
    {
        return [
            'external_id' => $product->id,
            'sku' => $product->sku,
            'gtin' => $product->gtin?->value,
            'title' => $product->title,
            'description' => $product->description,
            'slug' => $product->slug,
            'url' => $product->url,
            'price' => $product->price,
            'cost_price' => $product->costPrice,
            'sale_price' => $product->salePrice,
            'compare_price' => $product->comparePrice,
            'stock' => $product->stock,
            'is_active' => $product->isActive,
            'vat_exclusive' => $product->vatExclusive,
            'weight' => $product->weight,
            'meta_title' => $product->metaTitle,
            'meta_description' => $product->metaDescription,
            'sort_order' => $product->sortOrder,
            'shopwired_created_at' => $product->createdAt,
            'shopwired_updated_at' => $product->updatedAt,
        ];
    }

    /**
     * Embed-dependent attributes (always written by full save, conditionally by webhook save).
     *
     * @return array<string, mixed>
     */
    private static function embedAttributes(Product $product): array
    {
        return [
            'vat_relief' => $product->vatRelief,
            'category_ids' => $product->categoryIds,
            'images' => \array_map(
                static fn(ProductImage $img): array => $img->toArray(),
                $product->images,
            ),
            'custom_fields' => $product->rawCustomFields,
            'filters' => $product->rawFilters,
        ];
    }

    /**
     * Resolve variations to ProductVariationView when loaded and included.
     *
     * @param list<string> $includes
     *
     * @return list<ProductVariationView>|null
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveVariations(ProductModel $model, array $includes): ?array
    {
        if (! $model->relationLoaded('variations') || ! \in_array('variations', $includes, true)) {
            return null;
        }

        return \array_values($model->variations->map(
            fn(ProductVariationModel $m): ProductVariationView => $this->variationMapper->toViewDomain(
                model: $m,
                parentPrice: $model->price,
                parentSalePrice: $model->sale_price,
                vatExclusive: $model->vat_exclusive,
            ),
        )->all());
    }

    /**
     * Get cost price from Linnworks only (no ShopWired fallback).
     *
     * View models source cost prices exclusively from Linnworks.
     * Returns null when the SKU is missing or Linnworks has no price.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function getLinnworksCostPrice(?string $sku): ?float
    {
        if ($sku === null) {
            return null;
        }

        return $this->costPriceFactory->getCostPrice($sku);
    }

    /**
     * Conditionally type custom fields via factory.
     *
     * @param list<string> $includes
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     */
    private function resolveCustomFields(ProductModel $model, array $includes): array
    {
        if (! \in_array('custom_fields', $includes, true)) {
            return [];
        }

        /** @var array<string, mixed> $rawCustomFields */
        $rawCustomFields = $model->custom_fields;

        return $this->customFieldFactory->fromRawFields($rawCustomFields);
    }

    /**
     * Conditionally type filters via factory.
     *
     * @param list<string> $includes
     *
     * @return list<ProductFilter>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveFilters(ProductModel $model, array $includes): array
    {
        if (! \in_array('filters', $includes, true)) {
            return [];
        }

        /** @var array<int|string, list<string>> $rawFilters */
        $rawFilters = $model->filters ?? [];

        return $this->filterFactory->fromRawFilters($rawFilters);
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
