<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\Enums\BuildStatus;
use App\Application\QuickBooks\OrderSync\Enums\FailReason;
use App\Application\QuickBooks\OrderSync\Enums\SkipReason;

/**
 * A built draft, or the reason the order is skipped or failed without one.
 */
final readonly class DocumentBuildResult
{
    private function __construct(
        public BuildStatus $status,
        public InvoiceDraftDTO|SalesReceiptDraftDTO|null $draft,
        public ?SkipReason $skipReason,
        public ?FailReason $failReason,
    ) {}

    public static function built(InvoiceDraftDTO|SalesReceiptDraftDTO $draft): self
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
