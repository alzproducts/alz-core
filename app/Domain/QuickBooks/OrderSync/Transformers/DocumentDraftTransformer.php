<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Transformers;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FailReason;
use App\Domain\QuickBooks\OrderSync\Enums\SkipReason;
use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;
use App\Domain\QuickBooks\OrderSync\ValueObjects\DocumentBuildOutcome;
use App\Domain\QuickBooks\OrderSync\ValueObjects\DocumentCore;
use App\Domain\QuickBooks\OrderSync\ValueObjects\DocumentRunLabels;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrder;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrderLine;

/**
 * Turns one sync-source order into the Invoice or SalesReceipt the Zap would post, holding semantic keys, never QBO IDs (ADR-0018).
 * The draft is posted as built, with no totals guard.
 */
final class DocumentDraftTransformer
{
    /**
     * Outcome precedence: cancelled, then an unmapped payment method, then zero lines, then an unsupported rate.
     *
     * @throws UnsupportedVatRateException
     */
    public static function build(SyncOrder $order, DocumentRunLabels $labels): DocumentBuildOutcome
    {
        $docType = DocType::forPaymentMethod($order->paymentMethod);

        if ($order->cancelled || $docType === null || $order->lines === []) {
            return self::notBuilt($order, $docType);
        }

        $core = self::core($order, $order->lines, $docType, $labels);

        return DocumentBuildOutcome::built(match ($docType) {
            DocType::Invoice => InvoiceDraftTransformer::build($order, $core),
            DocType::SalesReceipt => SalesReceiptDraftTransformer::build($order, $core),
        });
    }

    private static function notBuilt(SyncOrder $order, ?DocType $docType): DocumentBuildOutcome
    {
        return match (true) {
            $order->cancelled => DocumentBuildOutcome::skipped(SkipReason::Cancelled),
            $docType === null => DocumentBuildOutcome::skipped(SkipReason::PaymentMethodUnmapped),
            default => DocumentBuildOutcome::failed(FailReason::ZeroLines),
        };
    }

    /**
     * @param non-empty-list<SyncOrderLine> $lines
     *
     * @throws UnsupportedVatRateException
     */
    private static function core(SyncOrder $order, array $lines, DocType $docType, DocumentRunLabels $labels): DocumentCore
    {
        return new DocumentCore(
            $labels->docNumberPrefix . 'A' . $order->reference,
            $order->createdAt,
            $order->billingEmail,
            CustomerDraftTransformer::build($order, $docType, $labels),
            LineDraftTransformer::goodsLines($order, $lines),
            LineDraftTransformer::shippingLine($order),
        );
    }
}
