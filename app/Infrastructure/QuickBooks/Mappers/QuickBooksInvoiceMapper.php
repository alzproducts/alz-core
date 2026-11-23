<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks\Mappers;

use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use LogicException;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Facades\Invoice;

/**
 * An invoice draft as a QBO create payload, built through the SDK facade (see `QuickBooksCustomerMapper`).
 */
final readonly class QuickBooksInvoiceMapper
{
    public static function toEntity(InvoiceDraftDTO $draft, ResolvedDocumentRefsDTO $refs): IPPInvoice
    {
        $invoice = Invoice::create(self::payload($draft, $refs));

        if (!$invoice instanceof IPPInvoice) {
            throw new LogicException('The SDK facade did not build an invoice');
        }

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(InvoiceDraftDTO $draft, ResolvedDocumentRefsDTO $refs): array
    {
        $shipVia = QuickBooksDocumentPayload::nonBlank($draft->shipVia);
        $poNumber = QuickBooksDocumentPayload::stringField($refs->poNumberFieldId, $draft->poNumberCustomField);

        return QuickBooksDocumentPayload::present([
            ...QuickBooksDocumentPayload::core($draft->core, $refs),
            'ClassRef' => QuickBooksDocumentPayload::ref(QuickBooksDocumentPayload::fixedRef($refs, FixedRefKey::SalesClass)),
            'SalesTermRef' => QuickBooksDocumentPayload::ref(QuickBooksDocumentPayload::required($refs->termsId)),
            'ShipMethodRef' => $shipVia === null ? null : ['value' => $shipVia, 'name' => $shipVia],
            'CustomField' => $poNumber === null ? null : [$poNumber],
            'BillAddr' => self::freeText($draft->billingAddressText),
            'ShipAddr' => self::freeText($draft->shippingAddressText),
            'AllowOnlineCreditCardPayment' => false,
            'AllowOnlineACHPayment' => false,
        ]);
    }

    /**
     * intentional: the whole free-form block goes on Line1, as the Zap posts it as one address field.
     *
     * @return array{Line1: string}|null
     */
    private static function freeText(string $address): ?array
    {
        return \mb_trim($address) === '' ? null : ['Line1' => $address];
    }
}
