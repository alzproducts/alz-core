<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\TaxType;
use App\Infrastructure\Catalog\Product\Models\ProductVariationViewModel;
use App\Infrastructure\Catalog\Product\Models\ProductViewModel;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;

/**
 * Assembles ProductViewModel (Eloquent) into ProductView (Domain) for API responses.
 *
 * Reads cost_price directly from the view (pre-joined from Linnworks) and
 * conditionally loads variations, custom fields, filters, and sale settings
 * based on requested includes.
 *
 * Extracted from ProductModelMapper to separate read-path view projection
 * from write-path model mapping.
 */
final readonly class ProductViewAssembler
{
    public function __construct(
        private CustomFieldFactory $customFieldFactory,
        private ProductFilterFactory $filterFactory,
        private ProductVariationModelMapper $variationMapper,
        private SaleSettingsRepositoryInterface $saleSettingsRepo,
    ) {}

    /**
     * Convert Eloquent view model to ProductView for API responses.
     *
     * Cost price comes directly from the view (Linnworks join pre-applied).
     * Conditionally loads variations, custom fields, and filters based on includes.
     *
     * @param ProductViewModel $model The Eloquent view model (variations optionally eager-loaded)
     * @param list<ProductInclude> $includes Requested embeds
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function toViewDomain(ProductViewModel $model, array $includes = []): ProductView
    {
        $taxType = $model->vat_exclusive ? TaxType::ZeroRated : TaxType::Inclusive;

        return new ProductView(
            id: IntId::from($model->external_id),
            sku: $model->sku !== null ? Sku::fromTrusted($model->sku) : null,
            gtin: $model->gtin !== null ? Gtin::fromTrusted($model->gtin) : null,
            title: $model->title,
            description: $model->description,
            slug: $model->slug,
            url: $model->url,
            price: Money::fromTaxType($model->price, $taxType),
            costPrice: Money::nonZeroOrNull($model->cost_price, TaxType::Exclusive),
            salePrice: Money::nonZeroOrNull($model->sale_price, $taxType),
            comparePrice: Money::nonZeroOrNull($model->compare_price, $taxType),
            isOnSale: $model->is_on_sale,
            profitMargin: $model->profit_margin,
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
            saleSettings: $this->resolveSaleSettings($model, $includes),
            freeDelivery: self::resolveFreeDelivery($model->custom_fields),
        );
    }

    /**
     * Resolve variations to ProductVariationView when loaded and included.
     *
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductVariationView>|null
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveVariations(ProductViewModel $model, array $includes): ?array
    {
        if (! $model->relationLoaded('variations') || ! \in_array(ProductInclude::Variations, $includes, true)) {
            return null;
        }

        return \array_values($model->variations->map(
            fn(ProductVariationViewModel $m): ProductVariationView => $this->variationMapper->toViewDomain(
                model: $m,
                vatExclusive: $model->vat_exclusive,
            ),
        )->all());
    }

    /**
     * Conditionally type custom fields via factory.
     *
     * @param list<ProductInclude> $includes
     *
     * @return list<AbstractCustomFieldValue>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     */
    private function resolveCustomFields(ProductViewModel $model, array $includes): array
    {
        if (! \in_array(ProductInclude::CustomFields, $includes, true)) {
            return [];
        }

        /** @var array<string, mixed> $rawCustomFields */
        $rawCustomFields = $model->custom_fields;

        return $this->customFieldFactory->fromRawFields($rawCustomFields);
    }

    /**
     * Conditionally type filters via factory.
     *
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductFilter>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveFilters(ProductViewModel $model, array $includes): array
    {
        if (! \in_array(ProductInclude::Filters, $includes, true)) {
            return [];
        }

        /** @var array<int|string, list<string>> $rawFilters */
        $rawFilters = $model->filters ?? [];

        return $this->filterFactory->fromRawFilters($rawFilters);
    }

    /**
     * Conditionally load sale settings from the repository.
     *
     * @param list<ProductInclude> $includes
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function resolveSaleSettings(ProductViewModel $model, array $includes): ?SaleSettings
    {
        if (! \in_array(ProductInclude::SaleSettings, $includes, true)) {
            return null;
        }

        return $this->saleSettingsRepo->findByProduct(IntId::from($model->external_id));
    }

    /**
     * Extract free delivery designation from raw custom fields.
     *
     * Returns null for empty/missing values (no designation).
     * Uses tryFrom() for safety — invalid values silently become null.
     *
     * @param array<string, mixed> $customFields
     */
    private static function resolveFreeDelivery(array $customFields): ?FreeDeliveryType
    {
        $value = $customFields['free_delivery'] ?? null;

        if (! \is_string($value) || $value === '') {
            return null;
        }

        return FreeDeliveryType::tryFrom($value);
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
