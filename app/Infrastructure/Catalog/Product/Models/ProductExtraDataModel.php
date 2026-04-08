<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Models;

use App\Domain\Catalog\Product\Commands\UpdateRetailPriceCommand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for catalog.product_extra_data table.
 *
 * Generic per-SKU extension data. RRP is the first column; more can be added later.
 *
 * @property string $id Internal UUID
 * @property string $sku SKU identifier
 * @property float|null $rrp Recommended retail price
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ProductExtraDataModel extends Model
{
    use HasUuids;

    protected $table = 'catalog.product_extra_data';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Convert a domain command to model attributes for bulk upsert.
     *
     * @return array{sku: string, rrp: float|null}
     */
    public static function attributesFromDomain(UpdateRetailPriceCommand $command): array
    {
        return [
            'sku' => $command->sku->value,
            'rrp' => $command->rrp->isZero() ? null : $command->rrp->toGross(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rrp' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
