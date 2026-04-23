<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Linnworks\Mappers\StockItemExtendedPropertyMapper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Eloquent model for linnworks.stock_item_extended_properties table.
 *
 * Stores Extended Properties (EPs) for stock items. Sync strategy:
 * delete all EPs for item → re-insert fresh from API.
 *
 * @property string $id Internal UUID
 * @property string $stock_item_id FK to stock_items.stock_item_id
 * @property string $pk_row_id Linnworks EP GUID
 * @property string $property_name
 * @property string $property_value
 * @property string $property_type
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<StockItemExtendedProperty>
 */
final class StockItemExtendedPropertyModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'linnworks.stock_item_extended_properties';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StockItemModel, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(
            StockItemModel::class,
            'stock_item_id',
            'stock_item_id',
        );
    }

    public function toDomain(): StockItemExtendedProperty
    {
        return StockItemExtendedPropertyMapper::fromModel($this);
    }

    /**
     * @param StockItemExtendedProperty $entity
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return StockItemExtendedPropertyMapper::toModelAttributes($entity);
    }
}
