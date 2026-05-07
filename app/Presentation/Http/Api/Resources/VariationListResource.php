<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\VariationListItem;
use App\Domain\ValueObjects\IntId;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API resource for VariationListItem domain value object.
 *
 * Serializes a variation as a first-class catalog row with denormalized parent context.
 *
 * @mixin VariationListItem
 */
final class VariationListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var VariationListItem $item */
        $item = $this->resource;

        return self::variationFields($item->variation)
            + self::parentContextFields($item)
            + self::conditionalIncludes($item);
    }

    /**
     * @return array<string, mixed>
     */
    private static function variationFields(ProductVariationView $variation): array
    {
        return self::identityAndPricingFields($variation) + [
            'stock' => [
                'available_stock' => $variation->stockLevel->availableStock,
                'physical_stock' => $variation->stockLevel->physicalStock,
                'stock_value' => $variation->stockValue?->toNet(),
            ],
            'weight' => $variation->weight?->value,
            'options' => \array_map(static fn(ProductVariationOption $opt): array => $opt->toArray(), $variation->options),
            'is_composite' => $variation->isComposite,
            'default_supplier' => $variation->defaultSupplier?->toArray(),
            'popularity' => $variation->popularity?->toArray(),
            'created_at' => $variation->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $variation->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function identityAndPricingFields(ProductVariationView $variation): array
    {
        return [
            'id' => $variation->id->value,
            'sku' => $variation->sku?->value,
            'gtin' => $variation->gtin?->value,
            'mpn' => $variation->mpn,
            'price' => $variation->price->toGross(),
            'cost_price' => $variation->costPrice?->toNet(),
            'sale_price' => $variation->salePrice?->toGross(),
            'rrp' => $variation->rrp?->toGross(),
            'effective_price' => $variation->effectivePrice->toGross(),
            'profit_margin' => $variation->profitMargin,
            'is_on_sale' => $variation->isOnSale,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parentContextFields(VariationListItem $item): array
    {
        return [
            'parent_product_id' => $item->parentProductId->value,
            'variation_title' => $item->variationTitle,
            'links' => [
                'public_url' => $item->links->publicUrl,
                'edit_website_url' => $item->links->editWebsiteUrl,
            ],
            'is_active' => $item->isActive,
            'vat_exclusive' => $item->vatExclusive,
            'vat_relief' => $item->vatRelief,
            'has_free_delivery' => $item->hasFreeDelivery,
            'free_delivery' => $item->freeDelivery?->value,
            'main_category_ids' => \array_map(static fn(IntId $id): int => $id->value, $item->mainCategoryIds),
            'image' => $item->resolvedImage?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function conditionalIncludes(VariationListItem $item): array
    {
        $data = [];

        if ($item->saleSettings !== null) {
            $data['sale_settings'] = $item->saleSettings->toArray();
        }

        if ($item->variation->suppliers !== null) {
            $data['suppliers'] = \array_map(static fn(ProductSupplier $s): array => $s->toArray(), $item->variation->suppliers);
        }

        if ($item->variation->inventory !== null) {
            $data['inventory'] = $item->variation->inventory->toArray();
        }

        return $data;
    }
}
