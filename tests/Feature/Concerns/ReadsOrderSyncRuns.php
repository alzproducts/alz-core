<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;

trait ReadsOrderSyncRuns
{
    /**
     * @return list<array<string, mixed>> each run row's request columns, oldest first
     */
    private function runRows(): array
    {
        return DB::table('quickbooks.order_sync_runs')
            ->orderBy('requested_at')
            ->get(['id', 'trigger', 'force_retry', 'range_from', 'range_to', 'order_references', 'status', 'candidate_count', 'finished_at'])
            ->map(static fn(object $row): array => (array) $row)
            ->values()
            ->all();
    }
}
