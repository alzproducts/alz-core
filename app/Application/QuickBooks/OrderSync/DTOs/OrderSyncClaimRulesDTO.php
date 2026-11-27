<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * Which stored rows a claim may take. A row refused for its unknown outcome may already hold a QuickBooks document;
 * a row failed for a missing item may post once the item exists.
 */
final readonly class OrderSyncClaimRulesDTO
{
    public function __construct(
        public bool $forceRetry,
        public bool $refuseUnknownOutcome,
        public bool $retryItemMissing,
    ) {}
}
