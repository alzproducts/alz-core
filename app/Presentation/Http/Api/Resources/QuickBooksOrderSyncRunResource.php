<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunDTO;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin OrderSyncRunDTO
 */
final class QuickBooksOrderSyncRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var OrderSyncRunDTO $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'trigger' => $run->trigger->value,
            'force_retry' => $run->forceRetry,
            'range' => $run->range === null ? null : [
                'from' => $run->range->from->format('Y-m-d'),
                'to' => $run->range->to->format('Y-m-d'),
            ],
            'references' => $run->references,
            'status' => $run->status->value,
            'candidate_count' => $run->candidateCount,
            'counts' => [
                'synced' => $run->counts->synced,
                'already_synced' => $run->counts->alreadySynced,
                'skipped' => $run->counts->skipped,
                'failed' => $run->counts->failed,
                'not_attempted' => $run->counts->notAttempted,
            ],
            'requested_at' => $run->requestedAt->format(DateTimeInterface::ATOM),
            'started_at' => $run->startedAt?->format(DateTimeInterface::ATOM),
            'finished_at' => $run->finishedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
