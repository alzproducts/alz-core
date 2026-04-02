<?php

declare(strict_types=1);

namespace App\Application\Inventory\Services;

use App\Application\Inventory\DTOs\VariationProcessingContextDTO;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Domain\Catalog\Product\Resolvers\VariationImageResolver;
use App\Domain\Catalog\Product\Resolvers\VariationOptionMatcher;
use App\Domain\Catalog\Product\Resolvers\VariationPriceResolver;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Inventory\Enums\ExtendedPropertyName;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use Webmozart\Assert\Assert;

/**
 * Build CreateStockItemParams from a ShopWired variation, product, and Linnworks template.
 *
 * Resolves pricing (including standard sign matching), images, MPN,
 * and supplier details into a ready-to-create params object.
 */
final readonly class StockItemParamsBuilderService
{
    public function __construct(
        private VariationPriceResolver $priceResolver,
        private VariationImageResolver $imageResolver,
        private VariationOptionMatcher $optionMatcher,
    ) {}

    /**
     * Build params for creating a Linnworks stock item from a variation.
     */
    public function build(ProductVariation $variation, VariationProcessingContextDTO $context): CreateStockItemParams
    {
        // Template is always validated to have a default supplier
        $supplier = $context->template->getDefaultSupplier();
        Assert::notNull($supplier, 'Template must have a default supplier (validated in validateTemplate)');

        $prices = $this->priceResolver->resolveFromProduct($variation, $context->product);
        $taxRate = $context->product->vatExclusive ? TaxRate::zero() : TaxRate::standard();

        // Build title: "Product Name - Option Values"
        $optionValues = $variation->optionValuesString();
        $title = $optionValues !== ''
            ? $context->product->title . ' - ' . $optionValues
            : $context->product->title;

        // Build Money based on tax treatment
        $retailPrice = $context->product->vatExclusive
            ? Money::zeroRated($prices->price)
            : Money::inclusive($prices->price);

        // Resolve purchase price: standard sign match > variation cost > null
        $purchasePrice = $this->resolvePurchasePrice($variation, $prices->costPrice, $context->standardSignVariations);

        // Resolve MPN: template supplier code (--copy-mpn) or variation MPN
        $mpn = $context->command->copyParentMpn
            ? $supplier->code
            : $variation->mpn;

        // Resolve image URL
        $imageUrl = $this->imageResolver->resolveUrl($variation, $context->product->images);

        return new CreateStockItemParams(
            categoryId: Guid::fromTrusted($context->template->categoryId),
            title: $title,
            retailPrice: $retailPrice,
            taxRate: $taxRate,
            supplierId: $context->command->noSupplier ? null : $supplier->supplierId,
            purchasePrice: $context->command->noSupplier ? null : $purchasePrice,
            barcode: $variation->gtin,
            mpn: $mpn,
            supplierCode: $context->command->noSupplier ? null : $supplier->code,
            extendedProperties: [
                ExtendedPropertyName::ShopId->value => (string) $variation->id,
            ],
            imageUrl: $imageUrl,
        );
    }

    /**
     * Resolve purchase price: standard sign match takes priority, then variation cost.
     *
     * @param list<ProductVariation>|null $standardSignVariations Reference variations for price matching
     */
    private function resolvePurchasePrice(
        ProductVariation $variation,
        ?float $costPrice,
        ?array $standardSignVariations,
    ): ?Money {
        if ($standardSignVariations !== null) {
            $matched = $this->optionMatcher->findMatch($variation, $standardSignVariations);

            if ($matched?->costPrice !== null) {
                return Money::exclusive($matched->costPrice);
            }
        }

        return $costPrice !== null ? Money::exclusive($costPrice) : null;
    }
}
