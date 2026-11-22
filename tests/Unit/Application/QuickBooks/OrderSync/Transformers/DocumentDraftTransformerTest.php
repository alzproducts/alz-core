<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\Enums\BuildStatus;
use App\Application\QuickBooks\OrderSync\Enums\FailReason;
use App\Application\QuickBooks\OrderSync\Enums\SkipReason;
use App\Application\QuickBooks\OrderSync\Results\DocumentBuildResult;
use App\Application\QuickBooks\OrderSync\Transformers\CustomerDraftTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\DocumentDraftTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\InvoiceDraftTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\LineDraftTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\SalesReceiptDraftTransformer;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;

#[CoversClass(DocumentDraftTransformer::class)]
#[CoversClass(LineDraftTransformer::class)]
#[CoversClass(CustomerDraftTransformer::class)]
#[CoversClass(InvoiceDraftTransformer::class)]
#[CoversClass(SalesReceiptDraftTransformer::class)]
#[CoversClass(DocType::class)]
#[CoversClass(PaymentKey::class)]
#[CoversClass(TaxKey::class)]
#[CoversClass(SupportedVatRate::class)]
#[CoversClass(DocumentBuildResult::class)]
#[CoversClass(InvoiceDraftDTO::class)]
#[CoversClass(SalesReceiptDraftDTO::class)]
#[CoversClass(DocumentRunLabelsDTO::class)]
#[CoversClass(DocumentCoreDTO::class)]
final class DocumentDraftTransformerTest extends TestCase
{
    /**
     * @return iterable<string, array{RawPaymentMethod, DocType}>
     */
    public static function docTypes(): iterable
    {
        yield 'Admin Order' => [RawPaymentMethod::AdminOrder, DocType::Invoice];
        yield 'Offline' => [RawPaymentMethod::Offline, DocType::Invoice];
        yield 'Credit' => [RawPaymentMethod::Credit, DocType::Invoice];
        yield 'PayPal' => [RawPaymentMethod::PayPal, DocType::SalesReceipt];
        yield 'Opayo Hosted' => [RawPaymentMethod::OpayoHosted, DocType::SalesReceipt];
        yield 'Opayo Direct' => [RawPaymentMethod::OpayoDirect, DocType::SalesReceipt];
        yield 'Opayo Form' => [RawPaymentMethod::OpayoForm, DocType::SalesReceipt];
        yield 'Sagepay Direct' => [RawPaymentMethod::SagepayDirect, DocType::SalesReceipt];
        yield 'Sagepay Form' => [RawPaymentMethod::SagepayForm, DocType::SalesReceipt];
        yield 'Sagepay Hosted' => [RawPaymentMethod::SagepayHosted, DocType::SalesReceipt];
        yield 'ShopWired Payments' => [RawPaymentMethod::ShopWiredPayments, DocType::SalesReceipt];
    }

    #[Test]
    #[DataProvider('docTypes')]
    public function it_derives_the_doc_type_from_the_payment_method(RawPaymentMethod $method, DocType $expected): void
    {
        $outcome = self::build(SyncOrderFixture::order(paymentMethod: $method));

        self::assertSame(BuildStatus::Built, $outcome->status);
        self::assertSame($expected, $outcome->draft?->docType());
        self::assertNull($outcome->skipReason);
        self::assertNull($outcome->failReason);
    }

    /**
     * @return iterable<string, array{RawPaymentMethod, PaymentKey|null}>
     */
    public static function paymentKeys(): iterable
    {
        yield 'PayPal' => [RawPaymentMethod::PayPal, PaymentKey::PayPal];
        yield 'Opayo Direct' => [RawPaymentMethod::OpayoDirect, PaymentKey::CardOpayo];
        yield 'Opayo Form' => [RawPaymentMethod::OpayoForm, PaymentKey::CardOpayo];
        yield 'Opayo Hosted' => [RawPaymentMethod::OpayoHosted, PaymentKey::CardOpayo];
        yield 'ShopWired Payments' => [RawPaymentMethod::ShopWiredPayments, PaymentKey::ShopWiredPayments];
        yield 'Sagepay Direct' => [RawPaymentMethod::SagepayDirect, null];
        yield 'Sagepay Form' => [RawPaymentMethod::SagepayForm, null];
        yield 'Sagepay Hosted' => [RawPaymentMethod::SagepayHosted, null];
    }

    #[Test]
    #[DataProvider('paymentKeys')]
    public function it_gives_a_sales_receipt_the_zaps_payment_key(RawPaymentMethod $method, ?PaymentKey $expected): void
    {
        $receipt = self::receipt(SyncOrderFixture::order(paymentMethod: $method));

        self::assertSame($expected, $receipt->paymentKey);
    }

    #[Test]
    public function it_gives_no_payment_key_to_an_invoice_payment_method(): void
    {
        self::assertNull(PaymentKey::forPaymentMethod(RawPaymentMethod::AdminOrder));
        self::assertNull(PaymentKey::forPaymentMethod(RawPaymentMethod::Offline));
        self::assertNull(PaymentKey::forPaymentMethod(RawPaymentMethod::Credit));
    }

    #[Test]
    public function it_skips_a_cancelled_order_before_any_other_check(): void
    {
        $outcome = self::build(SyncOrderFixture::order(
            lines: [],
            paymentMethod: RawPaymentMethod::Credit,
            cancelled: true,
        ));

        self::assertSame(BuildStatus::Skipped, $outcome->status);
        self::assertSame(SkipReason::Cancelled, $outcome->skipReason);
    }

    #[Test]
    public function it_fails_a_zero_line_credit_order_rather_than_skipping_it(): void
    {
        $outcome = self::build(SyncOrderFixture::order(lines: [], paymentMethod: RawPaymentMethod::Credit));

        self::assertSame(BuildStatus::Failed, $outcome->status);
        self::assertSame(FailReason::ZeroLines, $outcome->failReason);
        self::assertNull($outcome->skipReason);
    }

    #[Test]
    public function it_fails_a_zero_line_order_before_checking_rates(): void
    {
        $outcome = self::build(SyncOrderFixture::order(
            lines: [],
            shippingTotalNet: '5.00',
            shippingVatRate: '17.5',
        ));

        self::assertSame(BuildStatus::Failed, $outcome->status);
        self::assertSame(FailReason::ZeroLines, $outcome->failReason);
        self::assertNull($outcome->draft);
        self::assertNull($outcome->skipReason);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unsupportedRates(): iterable
    {
        yield 'fractional' => ['20.5'];
        yield 'trailing non-zero fraction' => ['20.0001'];
        yield 'negative' => ['-20'];
        yield 'exponent' => ['2e1'];
        yield 'other whole rate' => ['5'];
        yield 'null' => [null];
        yield 'not a number' => ['abc'];
    }

    #[Test]
    #[DataProvider('unsupportedRates')]
    public function it_fails_an_unsupported_goods_rate(?string $rate): void
    {
        self::assertFailedForUnsupportedRate(self::build(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(), SyncOrderFixture::line(vatRate: $rate)],
        )));
    }

    #[Test]
    #[DataProvider('unsupportedRates')]
    public function it_fails_an_unsupported_rate_on_a_non_zero_shipping_line(?string $rate): void
    {
        self::assertFailedForUnsupportedRate(self::build(SyncOrderFixture::order(
            shippingTotalNet: '4.99',
            shippingVatRate: $rate,
        )));
    }

    #[Test]
    public function it_ignores_the_shipping_rate_when_shipping_is_zero(): void
    {
        $draft = self::invoice(SyncOrderFixture::order(shippingTotalNet: '0.000000', shippingVatRate: null));

        self::assertNull($draft->core->shippingLine);
    }

    /**
     * @return iterable<string, array{string, SupportedVatRate}>
     */
    public static function supportedRates(): iterable
    {
        yield '20' => ['20', SupportedVatRate::Standard];
        yield '20.0' => ['20.0', SupportedVatRate::Standard];
        yield '20.000000' => ['20.000000', SupportedVatRate::Standard];
        yield '0' => ['0', SupportedVatRate::Zero];
        yield '0.0' => ['0.0', SupportedVatRate::Zero];
        yield 'leading zero' => ['020.00', SupportedVatRate::Standard];
    }

    #[Test]
    #[DataProvider('supportedRates')]
    public function it_matches_a_supported_rate_by_decimal_value(string $rate, SupportedVatRate $expected): void
    {
        self::assertSame($expected, SupportedVatRate::fromRate($rate, '10042'));
    }

    /**
     * @return iterable<string, array{string, string, TaxKey, TaxKey}>
     */
    public static function taxKeys(): iterable
    {
        yield 'UK standard' => ['20.0', 'United Kingdom', TaxKey::Standard, TaxKey::Standard];
        yield 'UK zero' => ['0.0', 'United Kingdom', TaxKey::Zero, TaxKey::Zero];
        yield 'Ireland zero' => ['0.0', 'Ireland', TaxKey::EuGoods, TaxKey::EuShipping];
        yield 'Ireland standard' => ['20.0', 'Ireland', TaxKey::Standard, TaxKey::Standard];
        yield 'lower-case ireland' => ['0.0', 'ireland', TaxKey::Zero, TaxKey::Zero];
        yield 'padded Ireland' => ['0.0', ' Ireland', TaxKey::Zero, TaxKey::Zero];
        yield 'IE' => ['0.0', 'IE', TaxKey::Zero, TaxKey::Zero];
    }

    #[Test]
    #[DataProvider('taxKeys')]
    public function it_picks_goods_and_shipping_tax_keys_as_the_zap_does(
        string $rate,
        string $deliveryCountry,
        TaxKey $goodsKey,
        TaxKey $shippingKey,
    ): void {
        $draft = self::invoice(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(vatRate: $rate)],
            delivery: SyncOrderFixture::contact(country: $deliveryCountry),
            shippingTotalNet: '5.00',
            shippingVatRate: $rate,
        ));

        self::assertSame($goodsKey, $draft->core->goodsLines[0]->taxKey);
        self::assertSame($shippingKey, $draft->core->shippingLine?->taxKey);
    }

    #[Test]
    public function it_keys_ireland_on_the_delivery_country_not_the_billing_country(): void
    {
        $draft = self::invoice(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(unitPriceVat: '0', vatRate: '0.0')],
            billing: SyncOrderFixture::contact(country: 'Ireland'),
        ));

        self::assertSame(TaxKey::Zero, $draft->core->goodsLines[0]->taxKey);
    }

    #[Test]
    public function it_carries_the_item_sku_and_a_comma_free_title_on_each_line(): void
    {
        $draft = self::invoice(SyncOrderFixture::order(lines: [
            SyncOrderFixture::line(title: 'Clock, Day, Date', sku: 'CLK-9'),
        ]));

        $line = $draft->core->goodsLines[0];
        self::assertSame('CLK-9', $line->itemSku->value);
        self::assertSame('Clock Day Date', $line->description);
    }

    #[Test]
    public function it_emits_only_goods_lines_and_the_shipping_line(): void
    {
        $draft = self::invoice(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(), SyncOrderFixture::line(sku: 'ALZ-002')],
            shippingTotalNet: '3.00',
        ));

        self::assertCount(2, $draft->core->goodsLines);
        self::assertSame('3.00', $draft->core->shippingLine?->amount->toString());
    }

    #[Test]
    public function it_builds_an_invoice_with_the_zaps_fields(): void
    {
        $draft = self::invoice(SyncOrderFixture::order(
            billing: SyncOrderFixture::contact(name: 'Alex Buyer', company: 'Acme Ltd'),
            shippingMethod: 'Next Day (by 1pm)',
            comments: 'Our Reference PO-77',
        ));

        self::assertSame('A10042', $draft->core->docNumber);
        self::assertSame('2026-11-02 14:30:00', $draft->core->txnDate->format('Y-m-d H:i:s'));
        self::assertSame('buyer@example.test', $draft->core->billEmail);
        self::assertSame([FixedRefKey::Department, FixedRefKey::SalesClass], $draft->fixedRefKeys());
        self::assertSame('PO-77', $draft->poNumber);
        self::assertSame('Next Day', $draft->shipVia);
        self::assertSame(
            "Alex Buyer\nAcme Ltd\n1 High Street\nFlat 2\n\nLeeds, AB1 2CD\nUnited Kingdom",
            $draft->billingAddressText,
        );
        self::assertSame(
            "Care Home Ltd\nSam Carer\n1 High Street\nFlat 2\n\nLeeds, AB1 2CD\nUnited Kingdom",
            $draft->shippingAddressText,
        );
    }

    #[Test]
    public function it_takes_the_customers_own_sales_term_else_the_default_terms_key(): void
    {
        $draft = self::invoice(SyncOrderFixture::order());

        self::assertNull($draft->defaultTermsKeyFor('7'));

        foreach ([null, ''] as $none) {
            self::assertSame(FixedRefKey::DefaultTerms, $draft->defaultTermsKeyFor($none));
        }
    }

    #[Test]
    public function it_builds_a_sales_receipt_with_the_zaps_fields(): void
    {
        $receipt = self::receipt(SyncOrderFixture::order(
            paymentMethod: RawPaymentMethod::PayPal,
            delivery: SyncOrderFixture::contact(name: 'Sam Carer', postcode: 'D02 X285', country: 'Ireland'),
            transactionId: 'PAY-123',
        ));

        self::assertSame('A10042', $receipt->core->docNumber);
        self::assertSame('A10042', $receipt->paymentRefNum);
        self::assertSame([FixedRefKey::Department], $receipt->fixedRefKeys());
        self::assertSame('PAY-123', $receipt->transactionId);
        self::assertSame(
            ['1 High Street', 'Flat 2', 'Leeds', 'AB1 2CD', 'United Kingdom'],
            [
                $receipt->billingAddress->line1,
                $receipt->billingAddress->line2,
                $receipt->billingAddress->city,
                $receipt->billingAddress->postcode,
                $receipt->billingAddress->country,
            ],
        );
        self::assertSame('D02 X285', $receipt->shippingAddress->postcode);
        self::assertSame('Ireland', $receipt->shippingAddress->country);
    }

    #[Test]
    public function it_blanks_a_templated_transaction_id_on_a_sales_receipt(): void
    {
        $receipt = self::receipt(SyncOrderFixture::order(
            paymentMethod: RawPaymentMethod::OpayoDirect,
            transactionId: '{{txn}}',
        ));

        self::assertSame('', $receipt->transactionId);
    }

    #[Test]
    public function it_builds_the_invoice_customer_from_the_billing_contact(): void
    {
        $customer = self::invoice(SyncOrderFixture::order(
            billing: SyncOrderFixture::contact(name: 'Alex Middle Buyer', company: 'Acme Ltd'),
        ))->core->customer;

        self::assertSame('Acme Ltd', $customer->displayName);
        self::assertSame('Alex', $customer->givenName);
        self::assertSame('Middle', $customer->familyName);
        self::assertSame('Acme Ltd', $customer->companyName);
        self::assertSame('buyer@example.test', $customer->email);
        self::assertSame('01234 567890', $customer->phone);
        self::assertSame('1 High Street', $customer->billingAddress->line1);
        self::assertSame('Flat 2', $customer->billingAddress->line2);
        self::assertSame('Leeds', $customer->billingAddress->city);
        self::assertSame('AB1 2CD', $customer->billingAddress->postcode);
        self::assertSame('United Kingdom', $customer->billingAddress->country);
    }

    #[Test]
    public function it_names_a_company_less_sales_receipt_customer_by_name_and_postcode(): void
    {
        $customer = self::receipt(SyncOrderFixture::order(
            paymentMethod: RawPaymentMethod::ShopWiredPayments,
            billing: SyncOrderFixture::contact(name: 'Madonna'),
        ))->core->customer;

        self::assertSame('Madonna, AB1 2CD', $customer->displayName);
        self::assertSame('Madonna', $customer->givenName);
        self::assertSame('', $customer->familyName);
        self::assertSame('', $customer->companyName);
    }

    #[Test]
    public function it_applies_the_run_labels_to_the_doc_number_and_display_name(): void
    {
        $labels = new DocumentRunLabelsDTO('V7-', ' [v7]');

        $invoice = self::invoice(SyncOrderFixture::order(), $labels);
        $receipt = self::receipt(SyncOrderFixture::order(paymentMethod: RawPaymentMethod::PayPal), $labels);

        self::assertSame('V7-A10042', $invoice->core->docNumber);
        self::assertSame('Alex Buyer [v7]', $invoice->core->customer->displayName);
        self::assertSame('V7-A10042', $receipt->core->docNumber);
        self::assertSame('A10042', $receipt->paymentRefNum);
        self::assertSame('Alex Buyer, AB1 2CD [v7]', $receipt->core->customer->displayName);
    }

    #[Test]
    public function it_applies_no_labels_by_default(): void
    {
        $labels = DocumentRunLabelsDTO::none();

        self::assertSame('', $labels->docNumberPrefix);
        self::assertSame('', $labels->customerNameSuffix);
    }

    private static function build(SyncOrderDTO $order, ?DocumentRunLabelsDTO $labels = null): DocumentBuildResult
    {
        return DocumentDraftTransformer::build($order, $labels ?? DocumentRunLabelsDTO::none());
    }

    private static function invoice(SyncOrderDTO $order, ?DocumentRunLabelsDTO $labels = null): InvoiceDraftDTO
    {
        $draft = self::build($order, $labels)->draft;
        self::assertInstanceOf(InvoiceDraftDTO::class, $draft);

        return $draft;
    }

    private static function receipt(SyncOrderDTO $order, ?DocumentRunLabelsDTO $labels = null): SalesReceiptDraftDTO
    {
        $draft = self::build($order, $labels)->draft;
        self::assertInstanceOf(SalesReceiptDraftDTO::class, $draft);

        return $draft;
    }

    private static function assertFailedForUnsupportedRate(DocumentBuildResult $outcome): void
    {
        self::assertSame(BuildStatus::Failed, $outcome->status);
        self::assertSame(FailReason::TaxRateUnsupported, $outcome->failReason);
        self::assertNull($outcome->draft);
        self::assertNull($outcome->skipReason);
    }
}
