<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;

/**
 * Maps between ProductModel (Eloquent) and Product (Domain).
 *
 * Two mapping paths:
 * - `toDomain()`: Full conversion with custom fields/filters (internal use, returns Product)
 * - For API projection (ProductView), use ProductViewAssembler instead
 *
 * **Write path**: Use static `toModelAttributes()` for persistence.
 */
final class ProductModelMapper
{
    public function __construct(
        private readonly CustomFieldFactory $customFieldFactory,
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
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
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
            ...ProductModel::attributesFromDomain($product),
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
        return [
            ...ProductModel::attributesFromDomain($product),
            ...self::webhookEmbedAttributes($product, $presentEmbeds),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

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
     * Embed columns included only when the corresponding embed was present in the webhook payload.
     *
     * @param list<string> $presentEmbeds
     *
     * @return array<string, mixed>
     */
    private static function webhookEmbedAttributes(Product $product, array $presentEmbeds): array
    {
        $candidates = [
            'vat_relief' => ['vat_relief', $product->vatRelief],
            'categories' => ['category_ids', $product->categoryIds],
            'images' => ['images', \array_map(static fn(ProductImage $img): array => $img->toArray(), $product->images)],
            'custom_fields' => ['custom_fields', $product->rawCustomFields],
            'filters' => ['filters', $product->rawFilters],
        ];

        $attributes = [];

        foreach ($candidates as $embed => [$column, $value]) {
            if (\in_array($embed, $presentEmbeds, true)) {
                $attributes[$column] = $value;
            }
        }

        return $attributes;
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
