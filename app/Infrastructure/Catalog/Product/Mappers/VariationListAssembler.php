<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\Enums\VariationInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\VariationLinks;
use App\Domain\Catalog\Product\ValueObjects\VariationListItem;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Catalog\Product\Models\ProductVariationViewModel;
use App\Infrastructure\Shopwired\ShopwiredAdminUrlResolver;
use Webmozart\Assert\Assert;

/**
 * Assembles ProductVariationViewModel into VariationListItem for the variations list endpoint.
 *
 * Orchestrates include checks and delegates VO construction to self-constructing domain types.
 * Parent context is accessed via the eager-loaded product relationship.
 */
final readonly class VariationListAssembler
{
    public function __construct(
        private ProductVariationViewModelMapper $variationMapper,
    ) {}

    /**
     * @param list<VariationInclude> $includes Requested embeds
     */
    public function toListItem(ProductVariationViewModel $model, array $includes): VariationListItem
    {
        $parent = $model->product;
        Assert::isInstanceOf($parent, ProductModel::class);

        $variation = $this->variationMapper->toViewDomain(
            model: $model,
            vatExclusive: $parent->vat_exclusive,
            defaultSupplier: ProductVariationViewModelMapper::resolveDefaultSupplier($model),
            suppliers: \in_array(VariationInclude::Suppliers, $includes, true) ? ProductVariationViewModelMapper::resolveSuppliers($model) : null,
        );

        return self::buildListItem($model, $parent, $variation, $includes);
    }

    /**
     * @param list<VariationInclude> $includes
     */
    private static function buildListItem(
        ProductVariationViewModel $model,
        ProductModel $parent,
        ProductVariationView $variation,
        array $includes,
    ): VariationListItem {
        return new VariationListItem(
            variation: $variation,
            parentExternalId: $model->product_external_id,
            variationTitle: $model->variation_title,
            links: self::buildLinks($model, $parent, $variation),
            isActive: $model->parent_is_active,
            vatExclusive: $parent->vat_exclusive,
            vatRelief: (bool) $parent->vat_relief,
            freeDelivery: self::resolveFreeDelivery($model, $parent),
            mainCategoryIds: $model->parent_main_category_ids,
            resolvedImage: VariationListItem::resolveImage($model->image_index, $parent->images),
            saleSettings: self::resolveSaleSettings($parent, $includes),
        );
    }

    private static function buildLinks(
        ProductVariationViewModel $model,
        ProductModel $parent,
        ProductVariationView $variation,
    ): VariationLinks {
        return new VariationLinks(
            parentPublicUrl: $parent->url,
            editWebsiteUrl: ShopwiredAdminUrlResolver::productEditUrl($model->product_external_id),
            sku: $variation->sku,
        );
    }

    private static function resolveFreeDelivery(ProductVariationViewModel $model, ProductModel $parent): ?FreeDeliveryType
    {
        return $model->parent_has_free_delivery
            ? self::resolveFreeDeliveryFromCustomFields($parent->custom_fields)
            : null;
    }

    /**
     * @param list<VariationInclude> $includes
     */
    private static function resolveSaleSettings(ProductModel $parent, array $includes): ?SaleSettings
    {
        if (! \in_array(VariationInclude::SaleSettings, $includes, true)) {
            return null;
        }

        return SaleSettings::fromRawCustomFields($parent->custom_fields);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private static function resolveFreeDeliveryFromCustomFields(array $customFields): ?FreeDeliveryType
    {
        $value = $customFields['free_delivery'] ?? null;

        if (! \is_string($value) || $value === '') {
            return null;
        }

        return FreeDeliveryType::tryFrom($value);
    }
}
