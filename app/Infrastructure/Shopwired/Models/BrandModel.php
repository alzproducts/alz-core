<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Brand\ValueObjects\Brand;
use App\Domain\Catalog\Brand\ValueObjects\BrandImage;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for shopwired.brands table.
 *
 * Stores ShopWired brands synced from the API.
 * The `external_id` is ShopWired's brand ID, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired brand ID
 * @property CarbonImmutable $shopwired_created_at ShopWired creation timestamp
 * @property string $title Brand title
 * @property string|null $description Brand description
 * @property string $slug URL slug
 * @property string $url Full URL path
 * @property bool $active Whether brand is active
 * @property bool $featured Whether brand is featured
 * @property int $sort_order Display ordering
 * @property string|null $meta_title SEO title
 * @property string|null $meta_description SEO description
 * @property string|null $meta_keywords SEO keywords
 * @property string|null $image_url Brand image URL
 * @property array<string, mixed> $custom_fields Custom field key-value pairs
 * @property CarbonImmutable $created_at When first synced locally
 * @property CarbonImmutable $updated_at When last updated locally
 *
 * @implements EloquentDomainMappableInterface<Brand>
 */
final class BrandModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'shopwired.brands';

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
            'sort_order' => 'integer',
            'custom_fields' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Convert this Eloquent model to its corresponding Domain object.
     */
    public function toDomain(): Brand
    {
        return new Brand(
            id: $this->external_id,
            createdAt: $this->shopwired_created_at->toDateTimeImmutable(),
            title: $this->title,
            description: $this->description,
            slug: $this->slug,
            url: $this->url,
            active: $this->active,
            featured: $this->featured,
            sortOrder: $this->sort_order,
            metaTitle: $this->meta_title,
            metaKeywords: $this->meta_keywords,
            metaDescription: $this->meta_description,
            image: $this->image_url !== null ? new BrandImage($this->image_url) : null,
            customFields: $this->custom_fields,
        );
    }

    /**
     * Convert this Eloquent model to the API view projection.
     *
     * Conditionally loads description and customFields based on the includes list.
     * Unloaded fields are null.
     *
     * @param list<string> $includes Embed names to load
     */
    public function toViewDomain(array $includes = []): BrandView
    {
        return new BrandView(
            id: IntId::fromTrusted($this->external_id),
            title: $this->title,
            slug: $this->slug,
            url: $this->url,
            active: $this->active,
            featured: $this->featured,
            sortOrder: $this->sort_order,
            metaTitle: $this->meta_title,
            metaDescription: $this->meta_description,
            metaKeywords: $this->meta_keywords,
            image: $this->image_url !== null ? new BrandImage($this->image_url) : null,
            createdAt: $this->shopwired_created_at->toDateTimeImmutable(),
            description: \in_array('description', $includes, true) ? $this->description : null,
            customFields: \in_array('custom_fields', $includes, true) ? $this->custom_fields : null,
        );
    }

    /**
     * Convert a Domain Brand to Eloquent model attributes.
     *
     * Note: Does NOT include 'external_id' - that's used as the upsert key
     * and should be handled separately by the repository.
     *
     * @param Brand $entity The domain entity to convert
     *
     * @return array<string, mixed> Attributes for Eloquent create/update
     */
    public static function fromDomainAttributes(object $entity): array
    {
        /** @var Brand $entity */
        return [
            'shopwired_created_at' => $entity->createdAt,
            'title' => $entity->title,
            'description' => $entity->description,
            'slug' => $entity->slug,
            'url' => $entity->url,
            'active' => $entity->active,
            'featured' => $entity->featured,
            'sort_order' => $entity->sortOrder,
            'meta_title' => $entity->metaTitle,
            'meta_description' => $entity->metaDescription,
            'meta_keywords' => $entity->metaKeywords,
            'image_url' => $entity->image?->url,
            'custom_fields' => $entity->customFields,
        ];
    }
}
