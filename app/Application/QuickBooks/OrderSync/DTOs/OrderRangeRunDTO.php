<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use DateTimeImmutable;

/**
 * One accepted range run, bound to the company it was requested for. Both dates are inclusive business-calendar dates.
 */
final readonly class OrderRangeRunDTO
{
    public function __construct(
        public string $realmId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public bool $forceRetry,
        public string $runId,
    ) {}
}
