<?php

declare(strict_types=1);

namespace App\Application\Inventory\Services;

use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Domain\Catalog\Product\Resolvers\VariationImageResolver;
use App\Domain\Catalog\Product\Resolvers\VariationOptionMatcher;
use App\Domain\Catalog\Product\Resolvers\VariationPriceResolver;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Inventory\Enums\ExtendedPropertyName;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\Money;
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
     *
     * @param list<ProductVariation>|null $standardSignVariations Reference variations for price matching
     */
    public function build(
        ProductVariation $variation,
        Product $product,
        StockItemFull $template,
        GenerateVariantSkusCommand $command,
        ?array $standardSignVariations,
    ): CreateStockItemParams {
        // Template is always validated to have a default supplier
        $supplier = $template->getDefaultSupplier();
        Assert::notNull($supplier, 'Template must have a default supplier (validated in validateTemplate)');

        $prices = $this->priceResolver->resolveFromProduct($variation, $product);
        $taxRate = $product->vatExclusive ? TaxRate::zero() : TaxRate::standard();

        // Build title: "Product Name - Option Values"
        $optionValues = $variation->optionValuesString();
        $title = $optionValues !== ''
            ? $product->title . ' - ' . $optionValues
            : $product->title;

        // Build Money based on tax treatment
        $retailPrice = $product->vatExclusive
            ? Money::zeroRated($prices->price)
            : Money::inclusive($prices->price);

        // Resolve purchase price: standard sign match > variation cost > null
        $purchasePrice = $this->resolvePurchasePrice($variation, $prices->costPrice, $standardSignVariations);

        // Resolve MPN: template supplier code (--copy-mpn) or variation MPN
        $mpn = $command->copyParentMpn
            ? $supplier->code
            : $variation->mpn;

        // Resolve image URL
        $imageUrl = $this->imageResolver->resolveUrl($variation, $product->images);

        return new CreateStockItemParams(
            categoryId: Guid::fromTrusted($template->categoryId),
            title: $title,
            retailPrice: $retailPrice,
            taxRate: $taxRate,
            supplierId: $command->noSupplier ? null : Guid::fromTrusted($supplier->supplierId),
            purchasePrice: $command->noSupplier ? null : $purchasePrice,
            barcode: $variation->gtin,
            mpn: $mpn,
            supplierCode: $command->noSupplier ? null : $supplier->code,
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
