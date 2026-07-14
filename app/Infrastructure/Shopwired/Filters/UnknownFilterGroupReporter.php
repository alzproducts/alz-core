<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Filters;

use Illuminate\Support\Facades\Log;

/**
 * Per-request aggregator for unknown filter group optionNos encountered on the
 * read path. Exists so the many products in a single product-list request that
 * reference the same out-of-sync optionNo collapse into one summary log line
 * instead of one warning per product.
 *
 * **Lifecycle**: MUST be bound `scoped` — counts persist per request/job and
 * are flushed via app terminating callback. A singleton binding would
 * accumulate counts across requests under Octane and silently lie.
 */
final class UnknownFilterGroupReporter
{
    /** @var array<int, int> */
    private array $countsByOptionNo = [];

    private bool $registered = false;

    public function record(int $optionNo): void
    {
        $this->countsByOptionNo[$optionNo] = ($this->countsByOptionNo[$optionNo] ?? 0) + 1;

        $this->registerFlushOnce();
    }

    private function registerFlushOnce(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        \app()->terminating(function (): void {
            $this->flush();
        });
    }

    private function flush(): void
    {
        if ($this->countsByOptionNo === []) {
            return;
        }

        Log::warning('Unknown filter group optionNos encountered - re-run SyncFilterGroupsJob', [
            'by_option_no' => $this->countsByOptionNo,
        ]);
    }
}
