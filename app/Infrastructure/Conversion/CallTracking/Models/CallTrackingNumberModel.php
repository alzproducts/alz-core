<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $phone_number
 * @property bool $active
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 */
final class CallTrackingNumberModel extends Model
{
    use HasUuids;

    protected $table = 'customer_service.call_tracking_numbers';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
