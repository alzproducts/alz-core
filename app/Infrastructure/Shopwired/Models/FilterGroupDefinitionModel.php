<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for shopwired.filter_groups table.
 *
 * Stores ShopWired filter group definitions synced from the API.
 * The `external_id` is ShopWired's filter group ID, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired filter group ID
 * @property string $title Human-readable group name
 * @property int $option_no Unique option number used as key in product filters
 * @property int $sort_order Display ordering
 * @property CarbonImmutable $created_at When first synced locally
 * @property CarbonImmutable $updated_at When last updated locally
 *
 * @implements EloquentDomainMappableInterface<FilterGroupDefinition>
 */
final class FilterGroupDefinitionModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'shopwired.filter_groups';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'option_no' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Convert this Eloquent model to its corresponding Domain object.
     */
    public function toDomain(): FilterGroupDefinition
    {
        return new FilterGroupDefinition(
            id: $this->external_id,
            title: $this->title,
            optionNo: $this->option_no,
            sortOrder: $this->sort_order,
        );
    }

    /**
     * Convert a Domain FilterGroupDefinition to Eloquent model attributes.
     *
     * Note: Does NOT include 'external_id' - that's used as the upsert key
     * and should be handled separately by the repository.
     *
     * @param FilterGroupDefinition $entity The domain entity to convert
     *
     * @return array<string, mixed> Attributes for Eloquent create/update
     */
    public static function fromDomainAttributes(object $entity): array
    {
        /** @var FilterGroupDefinition $entity */
        return [
            'title' => $entity->title,
            'option_no' => $entity->optionNo,
            'sort_order' => $entity->sortOrder,
        ];
    }
}
