<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use DateTimeImmutable;

/**
 * Backfill historical orders from ShopWired for given date ranges.
 *
 * Each range is queued as a separate job, processed by Horizon workers
 * with rate limiting handled by the transport layer.
 */
final readonly class BackfillShopwiredOrdersUseCase
{
    public function __construct(
        private ShopwiredSyncDispatcherInterface $dispatcher,
    ) {}

    /**
     * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable}> $ranges
     *
     * @return int Number of jobs dispatched
     */
    public function execute(array $ranges): int
    {
        foreach ($ranges as $range) {
            $this->dispatcher->dispatchOrdersRangeSync($range['from'], $range['to']);
        }

        return \count($ranges);
    }
}
