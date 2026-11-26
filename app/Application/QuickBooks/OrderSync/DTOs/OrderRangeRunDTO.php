<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\ValueObjects\DateRange;

/**
 * One accepted range run, bound to the company it was requested for. The range holds inclusive business-calendar dates.
 */
final readonly class OrderRangeRunDTO
{
    public function __construct(
        public string $realmId,
        public DateRange $range,
        public bool $forceRetry,
        public string $runId,
    ) {}
}
