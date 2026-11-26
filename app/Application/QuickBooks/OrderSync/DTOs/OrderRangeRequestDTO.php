<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\ValueObjects\DateRange;

/**
 * A range staff asked to sync. The range holds inclusive business-calendar dates.
 */
final readonly class OrderRangeRequestDTO
{
    public function __construct(
        public DateRange $range,
        public bool $forceRetry,
    ) {}
}
