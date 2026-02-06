<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Jobs\Shopwired\SyncShopwiredOrdersRangeJob;
use DateTimeImmutable;

/**
 * Backfill historical orders from ShopWired for given date ranges.
 *
 * Each range is queued as a separate job, processed by Horizon workers
 * with rate limiting handled by the transport layer.
 */
final readonly class BackfillShopwiredOrdersUseCase
{
    /**
     * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable}> $ranges
     *
     * @return int Number of jobs dispatched
     */
    public function execute(array $ranges): int
    {
        foreach ($ranges as $range) {
            SyncShopwiredOrdersRangeJob::dispatch($range['from'], $range['to']);
        }

        return \count($ranges);
    }
}
