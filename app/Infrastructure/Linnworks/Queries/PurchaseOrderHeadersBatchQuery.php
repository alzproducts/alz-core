<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\LinnworksDateParser;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Row structure for PurchaseOrderHeadersBatchQuery results.
 *
 * @internal Implementation detail of PurchaseOrderHeadersBatchQuery
 */
final class PurchaseOrderHeadersBatchRow extends Data
{
    public function __construct(
        public readonly string $pkPurchaseID,
        public readonly string $fkSupplierId,
        public readonly string $fkLocationId,
        public readonly ?string $DateOfPurchase,
        public readonly ?string $DateOfDelivery,
        public readonly ?string $QuotedDeliveryDate,
        public readonly string $ExternalInvoiceNumber,
        public readonly string $PostagePaid,
        public readonly string $TotalCost,
        public readonly string $taxPaid,
        public readonly string $Status,
        public readonly string $Currency,
        public readonly string $ShippingTaxRate,
        public readonly string $ConversionRate,
        public readonly string $SupplierReferenceNumber,
        public readonly string $Locked,
        public readonly string $UnitAmountTaxIncludedType,
        public readonly string $ConvertedShippingCost,
        public readonly string $ConvertedShippingTax,
        public readonly string $ConvertedOtherCost,
        public readonly string $ConvertedOtherTax,
        public readonly string $ConvertedGrandTotal,
        public readonly string $LineCount,
        public readonly string $DeliveredLinesCount,
        public readonly string $NoteCount,
    ) {}
}

/**
 * Batch-fetch purchase order headers with computed counts via SQL subqueries.
 *
 * Returns complete PurchaseOrderHeader domain VOs with lineCount,
 * deliveredLinesCount, and noteCount calculated server-side by MS SQL.
 *
 * @extends AbstractLinnworksQuery<array<string, array{header: PurchaseOrderHeader, noteCount: int}>>
 *
 * @template-pattern Query Object
 */
final readonly class PurchaseOrderHeadersBatchQuery extends AbstractLinnworksQuery
{
    /**
     * @param list<Guid> $purchaseIds
     *
     * @throws InvalidArgumentException When purchase IDs are empty
     */
    public function __construct(
        private array $purchaseIds,
    ) {
        if ($this->purchaseIds === []) {
            throw new InvalidArgumentException('Purchase IDs cannot be empty');
        }
    }

    protected function buildQueryBody(): string
    {
        $inClause = SqlQueryBuilder::buildGuidInClause($this->purchaseIds);

        return <<<SQL
            SELECT
                p.pkPurchaseID, p.fkSupplierId, p.fkLocationId,
                p.DateOfPurchase, p.DateOfDelivery, p.QuotedDeliveryDate,
                p.ExternalInvoiceNumber, p.PostagePaid, p.TotalCost, p.taxPaid,
                p.Status, p.Currency, p.ShippingTaxRate, p.ConversionRate,
                p.SupplierReferenceNumber, p.Locked, p.UnitAmountTaxIncludedType,
                p.ConvertedShippingCost, p.ConvertedShippingTax,
                p.ConvertedOtherCost, p.ConvertedOtherTax, p.ConvertedGrandTotal,
                (SELECT COUNT(*) FROM [PurchaseItem] pi WHERE pi.fkPurchasId = p.pkPurchaseID) AS LineCount,
                (SELECT COUNT(*) FROM [PurchaseItem] pi WHERE pi.fkPurchasId = p.pkPurchaseID AND pi.Delivered = pi.Quantity) AS DeliveredLinesCount,
                (SELECT COUNT(*) FROM [Purchase_Notes] pn WHERE pn.fkPurchaseId = p.pkPurchaseID) AS NoteCount
            FROM [Purchase] p
            WHERE p.pkPurchaseID IN {$inClause}
            SQL;
    }

    /**
     * Map query results to headers keyed by purchase ID with note counts.
     *
     * @return array<string, array{header: PurchaseOrderHeader, noteCount: int}>
     *
     * @throws InvalidApiResponseException When status or date parsing fails
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        $results = [];

        foreach ($response->results as $row) {
            $parsed = PurchaseOrderHeadersBatchRow::from($row);
            $header = $this->toDomain($parsed);
            $results[$parsed->pkPurchaseID] = [
                'header' => $header,
                'noteCount' => (int) $parsed->NoteCount,
            ];
        }

        return $results;
    }

    /**
     * @throws InvalidApiResponseException When status or date parsing fails
     */
    private function toDomain(PurchaseOrderHeadersBatchRow $row): PurchaseOrderHeader
    {
        $status = PurchaseOrderStatus::tryFrom($row->Status);

        if ($status === null) {
            Log::critical('Linnworks SQL returned unknown purchase order status', [
                'status' => $row->Status,
                'purchaseId' => $row->pkPurchaseID,
            ]);

            throw new InvalidApiResponseException(
                'Linnworks',
                "Unknown purchase order status: {$row->Status}",
            );
        }

        return new PurchaseOrderHeader(
            pkPurchaseId: Guid::fromTrusted($row->pkPurchaseID),
            fkSupplierId: Guid::fromTrusted($row->fkSupplierId),
            fkLocationId: Guid::fromTrusted($row->fkLocationId),
            externalInvoiceNumber: $row->ExternalInvoiceNumber,
            status: $status,
            locked: $row->Locked === 'True',
            lineCount: (int) $row->LineCount,
            deliveredLinesCount: (int) $row->DeliveredLinesCount,
            currency: $row->Currency,
            supplierReferenceNumber: $row->SupplierReferenceNumber,
            unitAmountTaxIncludedType: (int) $row->UnitAmountTaxIncludedType,
            postagePaid: Money::exclusive((float) $row->PostagePaid),
            totalCost: (float) $row->TotalCost,
            taxPaid: (float) $row->taxPaid,
            shippingTaxRate: (float) $row->ShippingTaxRate < 0 ? null : TaxRate::fromPercentage((float) $row->ShippingTaxRate), // -1 means "not set"
            conversionRate: (float) $row->ConversionRate,
            convertedShippingCost: (float) $row->ConvertedShippingCost,
            convertedShippingTax: (float) $row->ConvertedShippingTax,
            convertedOtherCost: (float) $row->ConvertedOtherCost,
            convertedOtherTax: (float) $row->ConvertedOtherTax,
            convertedGrandTotal: (float) $row->ConvertedGrandTotal,
            dateOfPurchase: LinnworksDateParser::parse($row->DateOfPurchase),
            dateOfDelivery: LinnworksDateParser::parse($row->DateOfDelivery),
            quotedDeliveryDate: LinnworksDateParser::parse($row->QuotedDeliveryDate),
        );
    }
}
