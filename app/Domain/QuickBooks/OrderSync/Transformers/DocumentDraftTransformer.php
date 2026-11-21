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
     * Outcome precedence: cancelled, then zero lines, then an unsupported rate.
     *
     * @throws UnsupportedVatRateException
     */
    public static function build(SyncOrder $order, DocumentRunLabels $labels): DocumentBuildOutcome
    {
        if ($order->cancelled) {
            return DocumentBuildOutcome::skipped(SkipReason::Cancelled);
        }

        if ($order->lines === []) {
            return DocumentBuildOutcome::failed(FailReason::ZeroLines);
        }

        $docType = DocType::forPaymentMethod($order->paymentMethod);
        $core = self::core($order, $order->lines, $docType, $labels);

        return DocumentBuildOutcome::built(match ($docType) {
            DocType::Invoice => InvoiceDraftTransformer::build($order, $core),
            DocType::SalesReceipt => SalesReceiptDraftTransformer::build($order, $core),
        });
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
