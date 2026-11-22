<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks\Mappers;

use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use LogicException;
use QuickBooksOnline\API\Data\IPPSalesReceipt;
use QuickBooksOnline\API\Facades\SalesReceipt;

/**
 * A sales receipt draft as a QBO create payload, built through the SDK facade (see `QuickBooksCustomerMapper`).
 */
final readonly class QuickBooksSalesReceiptMapper
{
    public static function toEntity(SalesReceiptDraftDTO $draft, ResolvedDocumentRefsDTO $refs): IPPSalesReceipt
    {
        $receipt = SalesReceipt::create(self::payload($draft, $refs));

        if (!$receipt instanceof IPPSalesReceipt) {
            throw new LogicException('The SDK facade did not build a sales receipt');
        }

        return $receipt;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(SalesReceiptDraftDTO $draft, ResolvedDocumentRefsDTO $refs): array
    {
        $transactionId = QuickBooksDocumentPayload::stringField($refs->transactionIdFieldId, $draft->transactionIdCustomField);
        $paid = $draft->paymentKey !== null;

        return QuickBooksDocumentPayload::present([
            ...QuickBooksDocumentPayload::core($draft->core, $refs),
            'PaymentRefNum' => QuickBooksDocumentPayload::nonBlank($draft->paymentRefNum),
            'BillAddr' => self::address($draft->billingAddress),
            'ShipAddr' => self::address($draft->shippingAddress),
            'CustomField' => $transactionId === null ? null : [$transactionId],
            'PaymentMethodRef' => $paid ? QuickBooksDocumentPayload::ref(QuickBooksDocumentPayload::required($refs->paymentMethodId)) : null,
            'DepositToAccountRef' => $paid ? QuickBooksDocumentPayload::ref(QuickBooksDocumentPayload::required($refs->depositAccountId)) : null,
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private static function address(StructuredAddressDTO $address): ?array
    {
        $fields = QuickBooksDocumentPayload::present([
            'Line1' => QuickBooksDocumentPayload::nonBlank($address->line1),
            'Line2' => QuickBooksDocumentPayload::nonBlank($address->line2),
            'City' => QuickBooksDocumentPayload::nonBlank($address->city),
            'PostalCode' => QuickBooksDocumentPayload::nonBlank($address->postcode),
            'Country' => QuickBooksDocumentPayload::nonBlank($address->country),
        ]);

        return $fields === [] ? null : $fields;
    }
}
