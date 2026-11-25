<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

/**
 * The reason codes of the order sync. `retryable` means attempting again may succeed; a stored `failed` row is
 * still re-attempted only with `force_retry`, so `previously_failed` is retryable.
 */
enum SyncReasonCode: string
{
    case Cancelled = 'cancelled';
    case OrderNotFound = 'order_not_found';
    case PreviouslyFailed = 'previously_failed';
    case MappingMissing = 'mapping_missing';
    case ItemMissing = 'item_missing';
    case ZeroLines = 'zero_lines';
    case TaxRateUnsupported = 'tax_rate_unsupported';
    case ClosedPeriod = 'closed_period';
    case QboValidation = 'qbo_validation';
    case QboUnavailable = 'qbo_unavailable';
    case OutcomeUnknown = 'outcome_unknown';
    case InProgress = 'in_progress';
    case DeadlineExceeded = 'deadline_exceeded';

    public function outcome(): SyncOutcome
    {
        return match ($this) {
            self::Cancelled, self::OrderNotFound, self::PreviouslyFailed => SyncOutcome::Skipped,
            self::InProgress, self::DeadlineExceeded => SyncOutcome::NotAttempted,
            self::MappingMissing, self::ItemMissing, self::ZeroLines, self::TaxRateUnsupported,
            self::ClosedPeriod, self::QboValidation, self::QboUnavailable, self::OutcomeUnknown => SyncOutcome::Failed,
        };
    }

    public function retryable(): bool
    {
        return match ($this) {
            self::PreviouslyFailed, self::ItemMissing, self::QboUnavailable, self::OutcomeUnknown,
            self::InProgress, self::DeadlineExceeded => true,
            self::Cancelled, self::OrderNotFound, self::MappingMissing, self::ZeroLines,
            self::TaxRateUnsupported, self::ClosedPeriod, self::QboValidation => false,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Cancelled => 'The order is cancelled.',
            self::OrderNotFound => 'No order has this reference.',
            self::PreviouslyFailed => 'An earlier attempt failed; retry it with force_retry.',
            self::MappingMissing => 'The order needs a QuickBooks mapping that is not set up.',
            self::ItemMissing => 'A line\'s SKU has no matching QuickBooks item.',
            self::ZeroLines => 'The order produced no document lines.',
            self::TaxRateUnsupported => 'A VAT rate on the order is not supported.',
            self::ClosedPeriod => 'The document date is in a closed QuickBooks period.',
            self::QboValidation => 'QuickBooks rejected the document as invalid.',
            self::QboUnavailable => 'QuickBooks was unavailable and nothing was posted.',
            self::OutcomeUnknown => 'The post may have reached QuickBooks but no result came back.',
            self::InProgress => 'Another sync is already working on this order.',
            self::DeadlineExceeded => 'The request ran out of time before this order was posted.',
        };
    }
}
