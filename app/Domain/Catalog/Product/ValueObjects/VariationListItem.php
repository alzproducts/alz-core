<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\Resolvers\OneBasedIndexLookup;
use App\Domain\ValueObjects\IntId;

/**
 * Standalone variation row for the variations list endpoint.
 *
 * Composes a ProductVariationView (core pricing/stock/options) with denormalized
 * parent context so each row is self-contained — no secondary API call needed.
 *
 * Constructed by VariationListAssembler.
 */
final readonly class VariationListItem
{
    public IntId $parentProductId;

    /** @var list<IntId> */
    public array $mainCategoryIds;

    public bool $hasFreeDelivery;

    /**
     * @param ProductVariationView $variation Core variation data (pricing, stock, options, supplier, etc.)
     * @param int $parentExternalId Parent product's ShopWired ID
     * @param string $variationTitle Computed: "{Parent Title} - {option1} {option2} ..."
     * @param VariationLinks $links Public + edit URLs
     * @param bool $isActive Parent product visibility
     * @param bool $vatExclusive Parent tax treatment
     * @param bool $vatRelief Parent VAT relief flag
     * @param FreeDeliveryType|null $freeDelivery Parent delivery type enum
     * @param list<int> $mainCategoryIds Parent main category IDs
     * @param ProductImage|null $resolvedImage Image resolved from imageIndex + parent images
     * @param SaleSettings|null $saleSettings Parent sale metadata (conditional include)
     */
    public function __construct(
        public ProductVariationView $variation,
        int $parentExternalId,
        public string $variationTitle,
        public VariationLinks $links,
        public bool $isActive,
        public bool $vatExclusive,
        public bool $vatRelief,
        public ?FreeDeliveryType $freeDelivery,
        array $mainCategoryIds,
        public ?ProductImage $resolvedImage = null,
        public ?SaleSettings $saleSettings = null,
    ) {
        $this->parentProductId = IntId::from($parentExternalId);
        $this->mainCategoryIds = \array_map(
            static fn(int $id): IntId => IntId::from($id),
            $mainCategoryIds,
        );
        $this->hasFreeDelivery = $freeDelivery !== null && ! $freeDelivery->isNone();
    }

    /**
     * Resolve the variation image from imageIndex + parent images.
     *
     * Index semantics (1-based, ShopWired convention) are centralised in
     * `OneBasedIndexLookup`; this method only adapts the result to a `ProductImage` VO.
     *
     * @param list<array{id: int, url: string, description: string|null, sort_order: int}> $parentImages
     */
    public static function resolveImage(?int $imageIndex, array $parentImages): ?ProductImage
    {
        $imageData = OneBasedIndexLookup::at($imageIndex, $parentImages);

        return $imageData === null ? null : ProductImage::fromArray($imageData);
    }
}
