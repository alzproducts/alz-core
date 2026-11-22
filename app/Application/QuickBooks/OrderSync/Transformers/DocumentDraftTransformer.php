<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderLineDTO;
use App\Application\QuickBooks\OrderSync\Enums\FailReason;
use App\Application\QuickBooks\OrderSync\Enums\SkipReason;
use App\Application\QuickBooks\OrderSync\Results\DocumentBuildResult;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;

/**
 * Turns one sync-source order into the Invoice or SalesReceipt the Zap would post, holding semantic keys, never QBO IDs (ADR-0018).
 * The draft is posted as built, with no totals guard.
 */
final class DocumentDraftTransformer
{
    /**
     * Outcome precedence: cancelled, then zero lines, then an unsupported rate.
     */
    public static function build(SyncOrderDTO $order, DocumentRunLabelsDTO $labels): DocumentBuildResult
    {
        if ($order->cancelled) {
            return DocumentBuildResult::skipped(SkipReason::Cancelled);
        }

        if ($order->lines === []) {
            return DocumentBuildResult::failed(FailReason::ZeroLines);
        }

        return self::draft($order, $order->lines, $labels);
    }

    /**
     * @param non-empty-list<SyncOrderLineDTO> $lines
     */
    private static function draft(SyncOrderDTO $order, array $lines, DocumentRunLabelsDTO $labels): DocumentBuildResult
    {
        $docType = DocType::forPaymentMethod($order->paymentMethod);

        try {
            $core = self::core($order, $lines, $docType, $labels);
        } catch (UnsupportedVatRateException) {
            return DocumentBuildResult::failed(FailReason::TaxRateUnsupported);
        }

        return DocumentBuildResult::built(match ($docType) {
            DocType::Invoice => InvoiceDraftTransformer::build($order, $core),
            DocType::SalesReceipt => SalesReceiptDraftTransformer::build($order, $core),
        });
    }

    /**
     * @param non-empty-list<SyncOrderLineDTO> $lines
     *
     * @throws UnsupportedVatRateException
     */
    private static function core(SyncOrderDTO $order, array $lines, DocType $docType, DocumentRunLabelsDTO $labels): DocumentCoreDTO
    {
        return new DocumentCoreDTO(
            $labels->docNumberPrefix . 'A' . $order->reference,
            $order->createdAt,
            $order->billingEmail,
            CustomerDraftTransformer::build($order, $docType, $labels),
            LineDraftTransformer::goodsLines($order, $lines),
            LineDraftTransformer::shippingLine($order),
        );
    }
}
