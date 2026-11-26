<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * Which stored rows a claim may take. A row refused for its unknown outcome may already hold a QuickBooks document.
 */
final readonly class OrderSyncClaimRulesDTO
{
    public function __construct(
        public bool $forceRetry,
        public bool $refuseUnknownOutcome,
    ) {}
}
