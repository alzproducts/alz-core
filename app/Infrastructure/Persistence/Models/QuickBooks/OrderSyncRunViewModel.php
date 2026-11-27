<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRunStatus;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Domain\ValueObjects\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property OrderSyncTrigger $trigger
 * @property bool $force_retry
 * @property CarbonImmutable|null $range_from
 * @property CarbonImmutable|null $range_to
 * @property string|null $order_references
 * @property OrderSyncRunStatus $status
 * @property int $candidate_count
 * @property int $synced
 * @property int $already_synced
 * @property int $skipped
 * @property int $failed
 * @property int $not_attempted
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class OrderSyncRunViewModel extends Model
{
    protected $table = 'quickbooks.order_sync_runs';

    public $timestamps = false;

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
            'trigger' => OrderSyncTrigger::class,
            'force_retry' => 'boolean',
            'range_from' => 'immutable_date',
            'range_to' => 'immutable_date',
            'status' => OrderSyncRunStatus::class,
            'candidate_count' => 'integer',
            'synced' => 'integer',
            'already_synced' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'not_attempted' => 'integer',
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function toDomain(): OrderSyncRunDTO
    {
        return new OrderSyncRunDTO(
            id: $this->id,
            trigger: $this->trigger,
            forceRetry: $this->force_retry,
            range: $this->range_from !== null && $this->range_to !== null
                ? new DateRange($this->range_from->toDateTimeImmutable(), $this->range_to->toDateTimeImmutable())
                : null,
            references: self::references($this->order_references),
            status: $this->status,
            candidateCount: $this->candidate_count,
            counts: new OrderSyncRunCountsDTO($this->synced, $this->already_synced, $this->skipped, $this->failed, $this->not_attempted),
            requestedAt: $this->requested_at->toDateTimeImmutable(),
            startedAt: $this->started_at?->toDateTimeImmutable(),
            finishedAt: $this->finished_at?->toDateTimeImmutable(),
        );
    }

    /**
     * @return list<int>|null
     */
    private static function references(?string $array): ?array
    {
        if ($array === null) {
            return null;
        }

        $inner = \mb_trim($array, '{}');

        return $inner === '' ? [] : \array_map(\intval(...), \explode(',', $inner));
    }
}
