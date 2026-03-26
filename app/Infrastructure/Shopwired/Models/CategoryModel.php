<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Category\ValueObjects\Category;
use App\Domain\Catalog\Category\ValueObjects\CategoryImage;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Webmozart\Assert\Assert;

/**
 * Eloquent model for shopwired.categories table.
 *
 * Stores ShopWired categories synced from the API.
 * The `external_id` is ShopWired's category ID, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired category ID
 * @property CarbonImmutable $shopwired_created_at ShopWired creation timestamp
 * @property string $title Category title
 * @property string|null $description Primary description
 * @property string|null $description2 Secondary description
 * @property string $slug URL slug
 * @property string $url Full URL path
 * @property bool $active Whether category is active
 * @property bool $featured Whether category is featured
 * @property bool $trade_only Whether category is trade-only
 * @property int $sort_order Display ordering
 * @property string|null $meta_title SEO title
 * @property string|null $meta_description SEO description
 * @property string|null $meta_keywords SEO keywords
 * @property bool $meta_no_index Whether to noindex
 * @property string|null $image_url Category image URL
 * @property list<int> $parent_ids Parent category external IDs
 * @property array<string, mixed> $custom_fields Custom field key-value pairs
 * @property CarbonImmutable $created_at When first synced locally
 * @property CarbonImmutable $updated_at When last updated locally
 *
 * @implements EloquentDomainMappableInterface<Category>
 */
final class CategoryModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'shopwired.categories';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'shopwired_created_at' => 'immutable_datetime',
            'active' => 'boolean',
            'featured' => 'boolean',
            'trade_only' => 'boolean',
            'sort_order' => 'integer',
            'meta_no_index' => 'boolean',
            'parent_ids' => 'array',
            'custom_fields' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Convert this Eloquent model to its corresponding Domain object.
     */
    public function toDomain(): Category
    {
        return new Category(
            id: $this->external_id,
            createdAt: $this->shopwired_created_at->toDateTimeImmutable(),
            title: $this->title,
            description: $this->description,
            description2: $this->description2,
            slug: $this->slug,
            url: $this->url,
            active: $this->active,
            featured: $this->featured,
            tradeOnly: $this->trade_only,
            sortOrder: $this->sort_order,
            metaTitle: $this->meta_title,
            metaDescription: $this->meta_description,
            metaKeywords: $this->meta_keywords,
            metaNoIndex: $this->meta_no_index,
            image: $this->image_url !== null ? new CategoryImage($this->image_url) : null,
            parentIds: $this->parent_ids,
            customFields: $this->custom_fields,
        );
    }

    /**
     * Convert this Eloquent model to the API view projection.
     *
     * Conditionally loads description, description2, parentIds, customFields
     * based on the includes list. Unloaded fields are null.
     *
     * @param list<string> $includes Embed names to load
     *
     * @throws InvalidCustomFieldValueException When value type mismatches definition
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     */
    public function toViewDomain(array $includes = [], ?CustomFieldFactory $customFieldFactory = null): CategoryView
    {
        $customFields = null;

        if (\in_array('custom_fields', $includes, true)) {
            Assert::notNull($customFieldFactory, 'CustomFieldFactory required when custom_fields included');
            $customFields = $customFieldFactory->fromRawFields($this->custom_fields);
        }

        return new CategoryView(
            id: IntId::fromTrusted($this->external_id),
            title: $this->title,
            slug: $this->slug,
            url: $this->url,
            active: $this->active,
            featured: $this->featured,
            sortOrder: $this->sort_order,
            metaTitle: $this->meta_title,
            metaDescription: $this->meta_description,
            image: $this->image_url !== null ? new CategoryImage($this->image_url) : null,
            createdAt: $this->shopwired_created_at->toDateTimeImmutable(),
            description: \in_array('description', $includes, true) ? $this->description : null,
            description2: \in_array('description2', $includes, true) ? $this->description2 : null,
            parentIds: \in_array('parent_ids', $includes, true)
                ? \array_map(static fn(int $id): IntId => IntId::fromTrusted($id), $this->parent_ids)
                : null,
            customFields: $customFields,
        );
    }

    /**
     * Convert a Domain Category to Eloquent model attributes.
     *
     * Note: Does NOT include 'external_id' - that's used as the upsert key
     * and should be handled separately by the repository.
     *
     * @param Category $entity The domain entity to convert
     *
     * @return array<string, mixed> Attributes for Eloquent create/update
     */
    public static function fromDomainAttributes(object $entity): array
    {
        /** @var Category $entity */
        return [
            'shopwired_created_at' => $entity->createdAt,
            'title' => $entity->title,
            'description' => $entity->description,
            'description2' => $entity->description2,
            'slug' => $entity->slug,
            'url' => $entity->url,
            'active' => $entity->active,
            'featured' => $entity->featured,
            'trade_only' => $entity->tradeOnly,
            'sort_order' => $entity->sortOrder,
            'meta_title' => $entity->metaTitle,
            'meta_description' => $entity->metaDescription,
            'meta_keywords' => $entity->metaKeywords,
            'meta_no_index' => $entity->metaNoIndex,
            'image_url' => $entity->image?->url,
            'parent_ids' => $entity->parentIds,
            'custom_fields' => $entity->customFields,
        ];
    }
}
