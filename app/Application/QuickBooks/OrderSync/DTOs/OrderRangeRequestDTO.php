<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use DateTimeImmutable;

/**
 * A range staff asked to sync. Both dates are inclusive business-calendar dates.
 */
final readonly class OrderRangeRequestDTO
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public bool $forceRetry,
    ) {}
}
