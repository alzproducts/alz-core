<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;

/**
 * What the row-state rules say of one reference; a null outcome means claimable.
 */
final readonly class OrderEligibilityResult
{
    private function __construct(
        public ?SyncOutcome $outcome,
        public ?SyncReasonCode $reason,
    ) {}

    public static function claimable(): self
    {
        return new self(null, null);
    }

    public static function alreadySynced(): self
    {
        return new self(SyncOutcome::AlreadySynced, null);
    }

    public static function refused(SyncReasonCode $reason): self
    {
        return new self($reason->outcome(), $reason);
    }

    public function isClaimable(): bool
    {
        return $this->outcome === null;
    }
}
