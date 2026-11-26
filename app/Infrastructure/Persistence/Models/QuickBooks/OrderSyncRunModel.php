<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $trigger
 * @property bool $force_retry
 * @property string|null $range_from
 * @property string|null $range_to
 * @property string|null $order_references
 * @property string $status
 * @property int $candidate_count
 * @property int $synced
 * @property int $already_synced
 * @property int $skipped
 * @property int $failed
 * @property int $not_attempted
 * @property int $open_jobs
 * @property bool $job_failed
 * @property string $requested_at
 * @property string|null $started_at
 * @property string|null $finished_at
 */
final class OrderSyncRunModel extends Model
{
    protected $table = 'quickbooks.order_sync_runs';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'force_retry' => 'boolean',
            'job_failed' => 'boolean',
        ];
    }
}
