<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Infrastructure\Shopwired\Factories\ProductCustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;
use App\Infrastructure\Shopwired\Models\ProductModel;
use App\Infrastructure\Shopwired\Models\ProductVariationModel;

/**
 * Maps between ProductModel (Eloquent) and Product (Domain).
 *
 * **Read path**: Use `toDomain()` for typed custom fields via ProductCustomFieldFactory.
 * **Write path**: Use static `toModelAttributes()` for persistence.
 */
final class ProductModelMapper
{
    public function __construct(
        private readonly ProductCustomFieldFactory $customFieldFactory,
        private readonly ProductFilterFactory $filterFactory,
    ) {}

    /**
     * Convert Eloquent model with loaded relations to Domain Product.
     *
     * Requires 'variations' relation to be eager-loaded.
     * Custom fields are typed using ProductCustomFieldFactory.
     *
     * @param ProductModel $model The Eloquent model with loaded relations
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function toDomain(ProductModel $model): Product
    {
        /** @var list<ProductVariation> $variations */
        $variations = $model->variations->map(
            static fn(ProductVariationModel $m): ProductVariation => $m->toDomain(),
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
            vatRelief: $model->vat_relief,
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
            'vat_relief' => $product->vatRelief,
            'weight' => $product->weight,
            'meta_title' => $product->metaTitle,
            'meta_description' => $product->metaDescription,
            'category_ids' => $product->categoryIds,
            'images' => \array_map(
                static fn(ProductImage $img): array => $img->toArray(),
                $product->images,
            ),
            'custom_fields' => $product->rawCustomFields,
            'filters' => $product->rawFilters,
            'shopwired_created_at' => $product->createdAt,
            'shopwired_updated_at' => $product->updatedAt,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

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
