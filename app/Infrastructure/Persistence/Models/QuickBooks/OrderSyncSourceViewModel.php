<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $reference
 * @property CarbonImmutable $order_placed_at
 */
final class OrderSyncSourceViewModel extends Model
{
    protected $table = 'quickbooks.order_sync_source';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'reference' => 'integer',
            'order_placed_at' => 'immutable_datetime',
        ];
    }
}
