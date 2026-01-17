<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Infrastructure\Shopwired\Models\ProductModel;
use App\Infrastructure\Shopwired\Models\ProductVariationModel;

/**
 * Maps between ProductModel (Eloquent) and Product (Domain).
 *
 * Handles the complex transformations for Product entities including:
 * - Nested value objects (images, variations)
 * - JSONB array hydration
 *
 * **Custom Fields**: This mapper creates Products with EMPTY customFields.
 * For typed CustomFieldValue objects, use ProductDomainFactory::fromModel()
 * which joins raw custom_fields data with CustomFieldDefinitionRegistry.
 */
final class ProductModelMapper
{
    /**
     * Convert Eloquent model with loaded relations to Domain Product.
     *
     * Preferred entry point - handles relation conversion internally.
     * Requires 'variations' relation to be eager-loaded.
     *
     * NOTE: Creates Product with empty customFields. For full hydration,
     * use ProductDomainFactory::fromModel() instead.
     *
     * @param ProductModel $model The Eloquent model with loaded relations
     */
    public static function fromModelWithRelations(ProductModel $model): Product
    {
        /** @var list<ProductVariation> $variations */
        $variations = $model->variations->map(
            static fn(ProductVariationModel $m): ProductVariation => $m->toDomain(),
        )->all();

        return self::toDomain($model, $variations, []);
    }

    /**
     * Convert Eloquent model to Domain Product with pre-converted relations.
     *
     * Use fromModelWithRelations() unless you need to provide custom relation data.
     *
     * @param ProductModel                    $model        The Eloquent model to convert
     * @param list<ProductVariation>          $variations   Already-converted variation domain objects
     * @param list<AbstractCustomFieldValue>  $customFields Already-converted custom field value objects
     */
    public static function toDomain(
        ProductModel $model,
        array $variations,
        array $customFields,
    ): Product {
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
            categoryIds: $model->category_ids ?? [],
            variations: $variations,
            images: self::buildImages($model->images ?? []),
            customFields: $customFields,
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
            'custom_fields' => self::serializeCustomFields($product->customFields),
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

    /**
     * Serialize typed CustomFieldValue objects to raw JSONB format.
     *
     * Converts list<CustomFieldValue> back to {name: value, ...} for storage.
     *
     * @param list<AbstractCustomFieldValue> $customFields
     *
     * @return array<string, mixed>
     */
    private static function serializeCustomFields(array $customFields): array
    {
        $result = [];
        foreach ($customFields as $field) {
            $result[$field->name()] = $field->rawValue();
        }

        return $result;
    }
}
