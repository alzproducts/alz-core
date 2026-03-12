<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the sync_cursors table.
 *
 * Tracks the last successful sync timestamp per sync type,
 * enabling incremental (delta) sync strategies.
 *
 * @property string $id UUID primary key
 * @property string $sync_type Unique sync identifier (e.g. 'linnworks_stock_delta')
 * @property CarbonImmutable $cursor_value Timestamp of last successful sync
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SyncCursorModel extends Model
{
    use HasUuids;

    protected $table = 'public.sync_cursors';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cursor_value' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
