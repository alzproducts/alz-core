<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks\Mappers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\Transformers\DocumentDraftTransformer;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksDocumentPayload;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksInvoiceMapper;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksSalesReceiptMapper;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickBooksOnline\API\Data\IPPCustomField;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPLine;
use QuickBooksOnline\API\Data\IPPSalesReceipt;
use Tests\Unit\Application\QuickBooks\OrderSync\DocumentDraftFixture;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;

#[CoversClass(QuickBooksInvoiceMapper::class)]
#[CoversClass(QuickBooksSalesReceiptMapper::class)]
#[CoversClass(QuickBooksDocumentPayload::class)]
final class QuickBooksDocumentMappersTest extends TestCase
{
    #[Test]
    public function an_invoice_carries_the_core_fields_and_refs(): void
    {
        $invoice = QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs());

        $this->assertSame('cust-1', self::v($invoice->CustomerRef));
        $this->assertSame('SW-1001', $invoice->DocNumber);
        $this->assertSame('2026-11-20', $invoice->TxnDate);
        $this->assertSame('jo@example.test', $invoice->BillEmail->Address);
        $this->assertSame('TaxExcluded', self::v($invoice->GlobalTaxCalculation));
        $this->assertSame('dept-1', self::v($invoice->DepartmentRef));
        $this->assertSame('class-1', self::v($invoice->ClassRef));
        $this->assertSame('terms-1', self::v($invoice->SalesTermRef));
        $this->assertSame('Royal Mail', self::v($invoice->ShipMethodRef));
        $this->assertSame("Jo Bloggs\n1 High St\nLeeds", $invoice->BillAddr->Line1);
        $this->assertNull($invoice->ShipAddr);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function londonDates(): iterable
    {
        yield 'summer time, after London midnight' => ['2026-06-30T23:30:00Z', '2026-07-01'];
        yield 'winter time, before London midnight' => ['2026-12-31T23:30:00Z', '2026-12-31'];
    }

    #[Test]
    #[DataProvider('londonDates')]
    public function the_txn_date_is_the_london_date_the_order_was_placed(string $placedAt, string $expected): void
    {
        $lines = [SyncOrderFixture::line(sku: 'SKU-A')];
        $labels = new DocumentRunLabelsDTO('', '');

        $invoice = DocumentDraftTransformer::build(SyncOrderFixture::order($lines, RawPaymentMethod::AdminOrder, createdAt: $placedAt), $labels)->draft;
        $this->assertInstanceOf(InvoiceDraftDTO::class, $invoice);
        $this->assertSame($expected, QuickBooksInvoiceMapper::toEntity($invoice, DocumentDraftFixture::refs())->TxnDate);

        $receipt = DocumentDraftTransformer::build(SyncOrderFixture::order($lines, RawPaymentMethod::PayPal, createdAt: $placedAt), $labels)->draft;
        $this->assertInstanceOf(SalesReceiptDraftDTO::class, $receipt);
        $this->assertSame($expected, QuickBooksSalesReceiptMapper::toEntity($receipt, DocumentDraftFixture::refs())->TxnDate);
    }

    #[Test]
    public function an_invoice_has_online_card_and_ach_payment_off(): void
    {
        $invoice = QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs());

        $this->assertSame('false', $invoice->AllowOnlineCreditCardPayment);
        $this->assertSame('false', $invoice->AllowOnlineACHPayment);
    }

    #[Test]
    public function an_invoice_posts_the_po_number_on_its_field_id(): void
    {
        $field = self::customField(QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs()));

        $this->assertSame(['1', 'StringType', 'PO-77'], [self::v($field->DefinitionId), self::v($field->Type), $field->StringValue]);
    }

    #[Test]
    public function an_invoice_omits_a_blank_po_number_and_ship_method(): void
    {
        $invoice = QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(poNumber: ' ', shipVia: ''), DocumentDraftFixture::refs());

        $this->assertNull($invoice->CustomField);
        $this->assertNull(self::v($invoice->ShipMethodRef));
    }

    #[Test]
    public function goods_lines_carry_item_price_quantity_and_tax_code(): void
    {
        $lines = self::lines(QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs()));

        $this->assertCount(3, $lines);
        $this->assertSame(['20.00', 'Widget', 'SalesItemLineDetail'], [$lines[0]->Amount, $lines[0]->Description, self::v($lines[0]->DetailType)]);
        $detail = $lines[0]->SalesItemLineDetail;
        $this->assertSame(['item-SKU-A', '10.00', 2, 'tax-std'], [self::v($detail->ItemRef), $detail->UnitPrice, $detail->Qty, self::v($detail->TaxCodeRef)]);
        $this->assertNull($lines[1]->Description);
        $this->assertSame(['item-SKU-B', 'tax-zero'], [self::v($lines[1]->SalesItemLineDetail->ItemRef), self::v($lines[1]->SalesItemLineDetail->TaxCodeRef)]);
    }

    #[Test]
    public function a_shipping_line_posts_on_the_shipping_item(): void
    {
        $invoiceLines = self::lines(QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs()));
        $receiptLines = self::lines(QuickBooksSalesReceiptMapper::toEntity(DocumentDraftFixture::receipt(), DocumentDraftFixture::refs()));

        foreach ([$invoiceLines[2], $receiptLines[2]] as $shipping) {
            $this->assertSame('4.99', $shipping->Amount);
            $this->assertSame(QuickBooksDocumentPayload::SHIPPING_ITEM_ID, self::v($shipping->SalesItemLineDetail->ItemRef));
            $this->assertSame('tax-std', self::v($shipping->SalesItemLineDetail->TaxCodeRef));
        }
    }

    #[Test]
    public function a_document_without_shipping_has_only_goods_lines(): void
    {
        $this->assertCount(2, self::lines(QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(shipping: false), DocumentDraftFixture::refs())));
    }

    #[Test]
    public function a_paid_receipt_carries_payment_refs_and_structured_addresses(): void
    {
        $receipt = QuickBooksSalesReceiptMapper::toEntity(DocumentDraftFixture::receipt(), DocumentDraftFixture::refs());

        $this->assertSame('TaxExcluded', self::v($receipt->GlobalTaxCalculation));
        $this->assertSame('dept-1', self::v($receipt->DepartmentRef));
        $this->assertNull($receipt->ClassRef);
        $this->assertSame('PAY-REF-1', $receipt->PaymentRefNum);
        $this->assertSame('pm-1', self::v($receipt->PaymentMethodRef));
        $this->assertSame('acct-1', self::v($receipt->DepositToAccountRef));
        $this->assertSame(['1 High St', null, 'Leeds', 'LS1 1AA', 'GB'], [
            $receipt->BillAddr->Line1,
            $receipt->BillAddr->Line2,
            $receipt->BillAddr->City,
            $receipt->BillAddr->PostalCode,
            $receipt->BillAddr->Country,
        ]);
        $this->assertNull($receipt->ShipAddr);
        $field = self::customField($receipt);
        $this->assertSame(['2', 'StringType', 'TXN-9'], [self::v($field->DefinitionId), self::v($field->Type), $field->StringValue]);
    }

    #[Test]
    public function an_unpaid_receipt_has_no_payment_refs(): void
    {
        $receipt = QuickBooksSalesReceiptMapper::toEntity(DocumentDraftFixture::receipt(paymentKey: null), DocumentDraftFixture::refs());

        $this->assertNull(self::v($receipt->PaymentMethodRef));
        $this->assertNull(self::v($receipt->DepositToAccountRef));
    }

    #[Test]
    public function a_ref_missing_past_the_postable_check_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);

        QuickBooksInvoiceMapper::toEntity(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs(['termsId' => null]));
    }

    private static function v(mixed $field): mixed
    {
        return \is_object($field) && \property_exists($field, 'value') ? $field->value : $field;
    }

    /**
     * @return list<IPPLine>
     */
    private static function lines(IPPInvoice|IPPSalesReceipt $document): array
    {
        $lines = $document->Line;
        self::assertIsArray($lines);
        self::assertContainsOnlyInstancesOf(IPPLine::class, $lines);

        return \array_values($lines);
    }

    private static function customField(IPPInvoice|IPPSalesReceipt $document): IPPCustomField
    {
        $fields = $document->CustomField;
        self::assertIsArray($fields);
        self::assertCount(1, $fields);
        self::assertInstanceOf(IPPCustomField::class, $fields[0]);

        return $fields[0];
    }
}
