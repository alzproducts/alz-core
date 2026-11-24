<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Resolvers;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerMatchDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncFailureDTO;
use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Enums\FailReason;
use App\Application\QuickBooks\OrderSync\Enums\SourceOrderDefect;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;

/**
 * Every cause of a failed order to its reason code and message.
 */
final class SyncOutcomeResolver
{
    private const string CURRENCY = 'GBP';

    /**
     * @param SyncReasonCode|null $storedCode the row's code before this attempt
     */
    public static function forDefect(SourceOrderDefect $defect, ?SyncReasonCode $storedCode): SyncFailureDTO
    {
        return self::keepingUnknownOutcome($storedCode, match ($defect) {
            SourceOrderDefect::PaymentMethodMissing => SyncFailureDTO::of(SyncReasonCode::MappingMissing, 'The order has no recognised payment method.'),
            SourceOrderDefect::SkuMissing => SyncFailureDTO::of(SyncReasonCode::ItemMissing, 'A line on the order has no SKU.'),
            SourceOrderDefect::VatRateInvalid => SyncFailureDTO::of(SyncReasonCode::TaxRateUnsupported),
            SourceOrderDefect::QuantityInvalid => SyncFailureDTO::of(SyncReasonCode::QboValidation, 'A line on the order has a quantity below one.'),
        });
    }

    /**
     * @param SyncReasonCode|null $storedCode the row's code before this attempt
     */
    public static function forBuildFailure(FailReason $reason, ?SyncReasonCode $storedCode): SyncFailureDTO
    {
        return self::keepingUnknownOutcome($storedCode, SyncFailureDTO::of(match ($reason) {
            FailReason::ZeroLines => SyncReasonCode::ZeroLines,
            FailReason::TaxRateUnsupported => SyncReasonCode::TaxRateUnsupported,
        }));
    }

    /**
     * @param SyncReasonCode|null $storedCode the row's code before this attempt; null at the batch create, after the
     *                                        DocNumber lookup found no document
     */
    public static function forUnavailable(?SyncReasonCode $storedCode): SyncFailureDTO
    {
        return self::keepingUnknownOutcome($storedCode, SyncFailureDTO::of(SyncReasonCode::QboUnavailable));
    }

    public static function forMissingItem(): SyncFailureDTO
    {
        return SyncFailureDTO::of(SyncReasonCode::ItemMissing);
    }

    /**
     * @param list<string> $missing as `ResolvedDocumentRefsDTO::missingFor()` names them; null when empty
     */
    public static function forMissingRefs(array $missing): ?SyncFailureDTO
    {
        return match (true) {
            $missing === [] => null,
            \array_any($missing, static fn(string $ref): bool => \str_starts_with($ref, 'item:')) => self::forMissingItem(),
            default => SyncFailureDTO::of(SyncReasonCode::MappingMissing),
        };
    }

    /**
     * Null when a document can post against the customer.
     */
    public static function forCustomer(CustomerResolutionDTO $customer): ?SyncFailureDTO
    {
        return match ($customer->failure) {
            null => self::forCurrency($customer->match),
            CustomerFailureKind::Inactive => SyncFailureDTO::of(SyncReasonCode::QboValidation, 'The order\'s QuickBooks customer is inactive.'),
            CustomerFailureKind::NameClash => SyncFailureDTO::of(SyncReasonCode::QboValidation, 'Another QuickBooks record already uses the customer\'s name.'),
            CustomerFailureKind::Faulted => self::forFault($customer->fault),
        };
    }

    /**
     * Null when the document was created.
     */
    public static function forBatch(BatchItemOutcomeResult $outcome): ?SyncFailureDTO
    {
        return match ($outcome->kind) {
            BatchOutcomeKind::Created => null,
            BatchOutcomeKind::Faulted => self::forFault($outcome->fault),
            BatchOutcomeKind::RateLimited => SyncFailureDTO::of(SyncReasonCode::QboUnavailable),
            BatchOutcomeKind::Ambiguous => SyncFailureDTO::of(SyncReasonCode::OutcomeUnknown),
        };
    }

    private static function forCurrency(?CustomerMatchDTO $match): ?SyncFailureDTO
    {
        return match ($match?->currency) {
            null, self::CURRENCY => null,
            '' => SyncFailureDTO::of(SyncReasonCode::QboValidation, 'The QuickBooks customer\'s currency could not be read.'),
            default => SyncFailureDTO::of(
                SyncReasonCode::QboValidation,
                "The QuickBooks customer's currency is {$match->currency}; only GBP customers can be posted to.",
            ),
        };
    }

    private static function forFault(?QuickBooksFault $fault): SyncFailureDTO
    {
        return $fault?->kind === QuickBooksFaultKind::ClosedPeriod
            ? SyncFailureDTO::of(SyncReasonCode::ClosedPeriod, fault: $fault)
            : SyncFailureDTO::of(SyncReasonCode::QboValidation, $fault?->message, $fault);
    }

    private static function keepingUnknownOutcome(?SyncReasonCode $storedCode, SyncFailureDTO $failure): SyncFailureDTO
    {
        if ($storedCode !== SyncReasonCode::OutcomeUnknown) {
            return $failure;
        }

        $unknown = SyncReasonCode::OutcomeUnknown;
        $message = "{$unknown->message()} This attempt stopped before posting: {$failure->code->value}.";

        return new SyncFailureDTO($unknown, $failure->code, $message, $failure->fault);
    }
}
