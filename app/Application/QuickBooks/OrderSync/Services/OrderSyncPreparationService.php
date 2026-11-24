<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderPreparationDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncFailureDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Results\OrderPreparationResult;
use App\Application\QuickBooks\OrderSync\Transformers\DocumentDraftTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\SourceOrderTransformer;
use LogicException;

/**
 * Builds orders' drafts. Needs no QuickBooks dependency, so it resolves with blank credentials (ADR-0015).
 */
final readonly class OrderSyncPreparationService
{
    public function build(OrderPreparationDTO $preparation): OrderPreparationResult
    {
        return OrderPreparationResult::of(\array_map(
            fn(ClaimedOrderDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO => $this->buildOne($order, $preparation->labels),
            $preparation->claimed,
        ));
    }

    /**
     * @param SyncReasonCode|null $storedCode the order's recorded code; null to get the build's own failure
     */
    public function buildRow(SourceOrderRowDTO $row, DocumentRunLabelsDTO $labels, ?SyncReasonCode $storedCode): InvoiceDraftDTO|SalesReceiptDraftDTO|SyncFailureDTO
    {
        $source = SourceOrderTransformer::fromRow($row);
        if ($source->defect !== null) {
            return SyncOutcomeResolver::forDefect($source->defect, $storedCode);
        }

        $result = DocumentDraftTransformer::build($source->order ?? throw new LogicException('A source order has an order or a defect'), $labels);
        if ($result->failReason !== null) {
            return SyncOutcomeResolver::forBuildFailure($result->failReason, $storedCode);
        }

        return $result->draft ?? throw new LogicException('A built order has a draft');
    }

    private function buildOne(ClaimedOrderDTO $order, DocumentRunLabelsDTO $labels): BuiltOrderDTO|OrderSyncOutcomeDTO
    {
        $built = $this->buildRow($order->row, $labels, $order->claim->storedCode);

        return $built instanceof SyncFailureDTO
            ? OrderSyncOutcomeDTO::failed($order, $built, null)
            : new BuiltOrderDTO($order, $built);
    }
}
