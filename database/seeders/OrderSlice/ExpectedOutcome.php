<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;

/**
 * The sync outcome an edge-case order must produce: `synced`, or `skipped:` / `failed:` plus a reason code from
 * {@see SyncReasonCode}.
 */
enum ExpectedOutcome: string
{
    case Synced = 'synced';
    case SkippedCancelled = 'skipped:cancelled';
    case SkippedPaymentMethodUnmapped = 'skipped:payment_method_unmapped';
    case FailedItemMissing = 'failed:item_missing';
    case FailedZeroLines = 'failed:zero_lines';
    case FailedQboValidation = 'failed:qbo_validation';
}
