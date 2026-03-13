<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for shopwired.webhook_events table.
 *
 * Tracks processed webhook events for centralised idempotency.
 *
 * @property string $id Internal UUID
 * @property int $subject_id ShopWired entity external ID
 * @property string $topic WebhookTopic value
 * @property int $webhook_id ShopWired's globally unique monotonic event ID
 * @property CarbonImmutable $event_time ShopWired event timestamp (observability)
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WebhookEventModel extends Model
{
    use HasUuids;

    protected $table = 'shopwired.webhook_events';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'webhook_id' => 'integer',
            'event_time' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
