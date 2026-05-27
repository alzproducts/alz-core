<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Models;

use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\Conversion\CallTracking\Enums\CallTrackingActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $call_tracking_visit_id
 * @property AdPlatform $ad_platform
 * @property CallTrackingActionStatus $status
 * @property string|null $external_id
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $completed_at
 */
final class CallTrackingActionModel extends Model
{
    use HasUuids;

    protected $table = 'customer_service.call_tracking_actions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ad_platform' => AdPlatform::class,
            'status' => CallTrackingActionStatus::class,
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
