<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\Enums\BuildStatus;
use App\Application\QuickBooks\OrderSync\Enums\FailReason;

/**
 * A built draft, or the reason the order failed without one.
 */
final readonly class DocumentBuildResult
{
    private function __construct(
        public BuildStatus $status,
        public InvoiceDraftDTO|SalesReceiptDraftDTO|null $draft,
        public ?FailReason $failReason,
    ) {}

    public static function built(InvoiceDraftDTO|SalesReceiptDraftDTO $draft): self
    {
        return new self(BuildStatus::Built, $draft, null);
    }

    public static function failed(FailReason $reason): self
    {
        return new self(BuildStatus::Failed, null, $reason);
    }
}
