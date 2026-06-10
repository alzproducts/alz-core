<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Brand\Mappers;

use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\Brand\ValueObjects\BrandImage;
use App\Domain\Catalog\Brand\ValueObjects\BrandLinks;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Models\BrandModel;
use App\Infrastructure\Shopwired\ShopwiredAdminUrlResolver;

/**
 * Assembles BrandModel (Eloquent) into BrandView (Domain) for API responses.
 *
 * Conditional includes drive which nullable fields are populated — unloaded
 * fields stay null. Typed custom fields resolved via the factory only when
 * BrandInclude::CustomFields is requested.
 */
final readonly class BrandViewAssembler
{
    public function __construct(
        private CustomFieldFactory $customFieldFactory,
    ) {}

    /**
     * Convert Eloquent model to BrandView for API responses.
     *
     * @param list<BrandInclude> $includes Requested embeds
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     */
    public function toViewDomain(BrandModel $model, array $includes = []): BrandView
    {
        return new BrandView(
            id: IntId::fromTrusted($model->external_id),
            title: $model->title,
            slug: $model->slug,
            links: self::buildLinks($model),
            active: $model->active,
            featured: $model->featured,
            sortOrder: $model->sort_order,
            metaTitle: $model->meta_title,
            metaDescription: $model->meta_description,
            image: self::buildImage($model),
            createdAt: $model->shopwired_created_at->toDateTimeImmutable(),
            description: \in_array(BrandInclude::Description, $includes, true) ? $model->description : null,
            description2: \in_array(BrandInclude::Description, $includes, true) ? self::extractDescription2($model->custom_fields) : null,
            customFields: $this->resolveCustomFields($model, $includes),
        );
    }

    private static function buildLinks(BrandModel $model): BrandLinks
    {
        return new BrandLinks(
            publicUrl: $model->url,
            editWebsiteUrl: ShopwiredAdminUrlResolver::brandEditUrl($model->external_id),
        );
    }

    private static function buildImage(BrandModel $model): ?BrandImage
    {
        return $model->image_url !== null ? new BrandImage($model->image_url) : null;
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private static function extractDescription2(array $customFields): ?string
    {
        $value = $customFields['description_2'] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param  list<BrandInclude>  $includes
     *
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function resolveCustomFields(BrandModel $model, array $includes): ?CustomFieldValueList
    {
        if (! \in_array(BrandInclude::CustomFields, $includes, true)) {
            return null;
        }

        $rawFields = $model->custom_fields;
        unset($rawFields['description_2']);

        return $this->customFieldFactory->fromRawFields($rawFields);
    }
}
