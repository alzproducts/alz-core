<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Models;

use App\Application\Catalog\Commands\CostPriceChangeCommand;
use App\Infrastructure\Catalog\Repositories\EloquentCostPriceChangeLogRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent write-model for `catalog.cost_price_changes`.
 *
 * Append-only audit log — exists purely as a bulk-insert target for
 * {@see EloquentCostPriceChangeLogRepository}. The app never reads it back into a Domain object,
 * so it carries no `toDomain()` projection (the `*LogModel` suffix exempts it from the
 * Catalog domain-conversion rule). `id` is supplied by `HasUuids`; `changed_at` is left to the
 * DB `useCurrent()` default and so must be omitted from inserted rows.
 * `$timestamps = false` because the only timestamp is the DB-filled `changed_at`
 * (no created_at/updated_at columns).
 *
 * @property string $id UUID primary key
 * @property string $sku
 * @property string $supplier_id Linnworks supplier GUID
 * @property string $supplier_name
 * @property string $old_cost_price Net cost price (decimal 12,4)
 * @property string $new_cost_price Net cost price (decimal 12,4)
 * @property CarbonImmutable $changed_at
 */
final class CostPriceChangeLogModel extends Model
{
    use HasUuids;

    protected $table = 'catalog.cost_price_changes';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array{sku: string, supplier_id: string, supplier_name: string, old_cost_price: float, new_cost_price: float}
     */
    public static function attributesFromDomain(CostPriceChangeCommand $change): array
    {
        return [
            'sku' => $change->sku->value,
            'supplier_id' => $change->supplierId->value,
            'supplier_name' => $change->supplierName,
            'old_cost_price' => $change->oldPrice->toNet(),
            'new_cost_price' => $change->newPrice->toNet(),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'old_cost_price' => 'decimal:4',
            'new_cost_price' => 'decimal:4',
            'changed_at' => 'immutable_datetime',
        ];
    }
}
