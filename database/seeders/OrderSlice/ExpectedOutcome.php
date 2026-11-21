<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * The sync outcome an edge-case order must produce: `synced`, or `skipped:` / `failed:` plus a reason code from
 * the QuickBooks order sync API contract (`docs/api-contracts/quickbooks-order-sync.md` §Outcomes and reason codes).
 */
enum ExpectedOutcome: string
{
    case Synced = 'synced';
    case SkippedCancelled = 'skipped:cancelled';
    case SkippedPaymentMethodUnmapped = 'skipped:payment_method_unmapped';
    case FailedItemMissing = 'failed:item_missing';
    case FailedZeroLines = 'failed:zero_lines';
}
