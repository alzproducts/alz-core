<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the operations.price_periods SCD2 table.
 *
 * Tracks retail price history per SKU. Each row represents a price period
 * with effective_from/effective_to boundaries. Current period has effective_to = NULL.
 *
 * @property string $id UUID primary key
 * @property string $sku SKU identifier
 * @property float $base_price_gross Base selling price (tax-inclusive)
 * @property float|null $sale_price_gross Sale price (null = no sale)
 * @property float $effective_price_gross Computed: sale or base price
 * @property bool $price_has_tax Whether VAT applies
 * @property CarbonImmutable $effective_from Period start
 * @property CarbonImmutable|null $effective_to Period end (null = current)
 * @property CarbonImmutable $created_at Record creation timestamp
 */
final class PricePeriodModel extends Model
{
    use HasUuids;

    protected $table = 'operations.price_periods';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Disable automatic timestamps — we manage created_at via DB default
     * and effective_from/effective_to explicitly.
     */
    public $timestamps = false;

    protected $fillable = [
        'sku',
        'base_price_gross',
        'sale_price_gross',
        'effective_price_gross',
        'price_has_tax',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price_gross' => 'float',
            'sale_price_gross' => 'float',
            'effective_price_gross' => 'float',
            'price_has_tax' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
