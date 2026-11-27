<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use DateTimeImmutable;

/**
 * The orders a catch-up run may queue: placed from `$from` up to but not including `$before`, with a `failed`
 * `item_missing` row re-taken only for an order placed from `$itemMissingSince`.
 */
final readonly class CatchUpCandidateWindowDTO
{
    public function __construct(
        public string $realmId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $before,
        public DateTimeImmutable $itemMissingSince,
    ) {}
}
