<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\Popularity;
use App\Domain\Catalog\Product\ValueObjects\ProductInventory;
use App\Domain\Catalog\Product\ValueObjects\ProductLinks;
use App\Domain\Catalog\Product\ValueObjects\ProductStock;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use App\Domain\Catalog\Product\ValueObjects\Stock;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Infrastructure\Catalog\Product\Mappers\ProductCustomFieldExtractor;
use App\Infrastructure\Catalog\Product\Mappers\ProductViewAssembler;
use App\Infrastructure\Catalog\Product\Mappers\ProductViewDerivations;
use App\Infrastructure\Shopwired\ShopwiredAdminUrlResolver;
use Webmozart\Assert\Assert;

/**
 * Builds a products-list ProductView from one `list_inputs` row, matching {@see ProductViewAssembler}
 * for the includes the list accepts. It reads nothing but its arguments: anything else would escape
 * the fragment fingerprint.
 */
final class ProductListViewAssembler
{
    public const array SUPPORTED_INCLUDES = [ProductInclude::Variations, ProductInclude::Inventory, ProductInclude::Stock];

    /**
     * @param list<ProductInclude> $includes
     *
     * @throws InvalidCustomFieldValueException When a custom field value mismatches its definition
     * @throws MissingRequiredDataException When the product has custom fields but no definitions exist
     */
    public static function toViewDomain(ProductListInputRow $row, SharedListInputs $shared, array $includes): ProductView
    {
        Assert::allInArray($includes, self::SUPPORTED_INCLUDES, 'The products list does not support this include');

        $typedCustomFields = $shared->typeProductFields($row->customFields);
        $allVariations = \array_map(
            static fn(ProductListVariationRow $variation): ProductVariationView => $variation->toViewDomain($row->vatExclusive),
            $row->variations,
        );
        $defaultSupplier = ProductViewDerivations::defaultSupplier(
            $row->stockItem !== null,
            $row->stockItem?->defaultSupplier?->toProductSupplier(),
            $allVariations,
        );

        return new ProductView(
            externalId: $row->externalId,
            sku: $row->sku,
            gtin: $row->gtin,
            title: $row->title,
            description: null,
            slug: $row->slug,
            links: new ProductLinks(
                publicUrl: $row->url,
                editWebsiteUrl: ShopwiredAdminUrlResolver::productEditUrl($row->externalId),
            ),
            price: $row->price,
            costPrice: $row->costPrice,
            salePrice: $row->salePrice,
            rrp: $row->rrp,
            effectivePrice: $row->effectivePrice,
            isOnSale: $row->isOnSale,
            profitMargin: $row->profitMargin,
            isActive: $row->isActive,
            vatExclusive: $row->vatExclusive,
            vatRelief: $row->vatRelief ?? false,
            metaTitle: $row->metaTitle,
            metaDescription: $row->metaDescription,
            categoryIds: $row->categoryIds,
            mainCategoryIds: $row->mainCategoryIds,
            variations: \in_array(ProductInclude::Variations, $includes, true) ? $allVariations : null,
            allVariations: $allVariations,
            images: $row->images,
            customFields: CustomFieldValueList::empty(),
            filters: [],
            sortOrder: $row->sortOrder,
            popularity: Popularity::fromRank($row->popularityRank, $row->popularityMax),
            createdAt: $row->shopwiredCreatedAt,
            updatedAt: $row->shopwiredUpdatedAt,
            meta: new ProductViewMeta($allVariations, $defaultSupplier, $row->stockItem?->isComposite),
            hasAnySale: $row->hasAnySale,
            parentAvailableStock: $row->availableStock,
            parentPhysicalStock: $row->physicalStock,
            saleSettings: null,
            freeDelivery: ProductCustomFieldExtractor::freeDelivery($typedCustomFields),
            suppliers: null,
            inventory: self::inventory($row, $includes),
            stock: self::stock($row, $includes, $allVariations),
            defaultSupplier: $defaultSupplier,
            isComposite: $row->stockItem?->isComposite,
            priceLastUpdatedAt: $row->priceLastUpdatedAt,
            costPriceLastUpdatedAt: $row->costPriceLastUpdatedAt,
            discontinued: $typedCustomFields->stringByName('discontinued'),
            preorderDate: $typedCustomFields->dateTimeByName('preorder_date'),
            otherStockStatus: $typedCustomFields->stringByName('other_stock_status'),
        );
    }

    /**
     * @param list<ProductInclude> $includes
     */
    private static function inventory(ProductListInputRow $row, array $includes): ?ProductInventory
    {
        if (! \in_array(ProductInclude::Inventory, $includes, true)) {
            return null;
        }

        return $row->stockItem?->toProductInventory();
    }

    /**
     * @param list<ProductInclude> $includes
     * @param list<ProductVariationView> $allVariations
     */
    private static function stock(ProductListInputRow $row, array $includes, array $allVariations): ?ProductStock
    {
        if (! \in_array(ProductInclude::Stock, $includes, true)) {
            return null;
        }

        $aggregate = Stock::fromParentAndVariants($row->availableStock, $row->physicalStock, $allVariations);

        return ProductListStockItemRow::buildProductStock($row->stockItem, $aggregate->availableStock, $aggregate->physicalStock);
    }
}
