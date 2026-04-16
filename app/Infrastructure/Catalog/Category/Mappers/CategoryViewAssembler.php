<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Category\Mappers;

use App\Domain\Catalog\Category\Enums\CategoryInclude;
use App\Domain\Catalog\Category\ValueObjects\CategoryImage;
use App\Domain\Catalog\Category\ValueObjects\CategoryLinks;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Models\CategoryModel;
use App\Infrastructure\Shopwired\ShopwiredAdminUrlResolver;

/**
 * Assembles CategoryModel (Eloquent) into CategoryView (Domain) for API responses.
 *
 * Conditional includes drive which nullable fields are populated — unloaded
 * fields stay null. Typed custom fields resolved via the factory only when
 * CategoryInclude::CustomFields is requested.
 */
final readonly class CategoryViewAssembler
{
    public function __construct(
        private CustomFieldFactory $customFieldFactory,
    ) {}

    /**
     * Convert Eloquent model to CategoryView for API responses.
     *
     * @param list<CategoryInclude> $includes Requested embeds
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     */
    public function toViewDomain(CategoryModel $model, array $includes = []): CategoryView
    {
        return new CategoryView(
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
            isMainCategory: ($model->custom_fields['is_main_category'] ?? false) === true,
            description: \in_array(CategoryInclude::Description, $includes, true) ? $model->description : null,
            description2: \in_array(CategoryInclude::Description2, $includes, true) ? $model->description2 : null,
            parentIds: self::resolveParentIds($model, $includes),
            customFields: $this->resolveCustomFields($model, $includes),
        );
    }

    private static function buildLinks(CategoryModel $model): CategoryLinks
    {
        return new CategoryLinks(
            publicUrl: $model->url,
            editWebsiteUrl: ShopwiredAdminUrlResolver::categoryEditUrl($model->external_id),
        );
    }

    private static function buildImage(CategoryModel $model): ?CategoryImage
    {
        return $model->image_url !== null ? new CategoryImage($model->image_url) : null;
    }

    /**
     * @param  list<CategoryInclude>  $includes
     * @return list<IntId>|null
     */
    private static function resolveParentIds(CategoryModel $model, array $includes): ?array
    {
        if (! \in_array(CategoryInclude::ParentIds, $includes, true)) {
            return null;
        }

        return \array_map(static fn(int $id): IntId => IntId::fromTrusted($id), $model->parent_ids);
    }

    /**
     * @param  list<CategoryInclude>  $includes
     * @return list<AbstractCustomFieldValue>|null
     *
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function resolveCustomFields(CategoryModel $model, array $includes): ?array
    {
        return \in_array(CategoryInclude::CustomFields, $includes, true)
            ? $this->customFieldFactory->fromRawFields($model->custom_fields)
            : null;
    }
}
