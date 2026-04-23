<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Models;

use App\Domain\Inventory\Enums\SkuUpdateReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for the operations.sku_changes audit table.
 *
 * Tracks cross-platform SKU update attempts between Linnworks and ShopWired.
 * Status is implicit: completed_at NULL = in-progress or failed.
 *
 * @property string $id UUID primary key
 * @property string $old_sku Original SKU before update
 * @property string $new_sku Target SKU after update
 * @property string $reason Business reason for change (enum value)
 * @property string|null $error_message Error details when update fails
 * @property CarbonImmutable $created_at When the update was initiated
 * @property CarbonImmutable|null $completed_at When the update completed successfully
 */
final class SkuChangeModel extends Model
{
    use HasUuids;

    protected $table = 'operations.sku_changes';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Disable automatic timestamps - we manage created_at/completed_at explicitly.
     *
     * - created_at: Set by database DEFAULT (gen_random_uuid)
     * - completed_at: Set manually on successful completion
     */
    public $timestamps = false;

    protected $fillable = [
        'old_sku',
        'new_sku',
        'reason',
        'error_message',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'reason' => SkuUpdateReason::class,
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
