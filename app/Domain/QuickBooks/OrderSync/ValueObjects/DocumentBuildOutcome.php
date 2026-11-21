<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\BuildStatus;
use App\Domain\QuickBooks\OrderSync\Enums\FailReason;
use App\Domain\QuickBooks\OrderSync\Enums\SkipReason;

/**
 * A built draft, or the reason the order is skipped or failed without one.
 */
final readonly class DocumentBuildOutcome
{
    private function __construct(
        public BuildStatus $status,
        public InvoiceDraft|SalesReceiptDraft|null $draft,
        public ?SkipReason $skipReason,
        public ?FailReason $failReason,
    ) {}

    public static function built(InvoiceDraft|SalesReceiptDraft $draft): self
    {
        return new self(BuildStatus::Built, $draft, null, null);
    }

    public static function skipped(SkipReason $reason): self
    {
        return new self(BuildStatus::Skipped, null, $reason, null);
    }

    public static function failed(FailReason $reason): self
    {
        return new self(BuildStatus::Failed, null, null, $reason);
    }
}
