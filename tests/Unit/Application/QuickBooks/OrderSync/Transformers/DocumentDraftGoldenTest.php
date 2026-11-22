<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\Enums\BuildStatus;
use App\Application\QuickBooks\OrderSync\Enums\FailReason;
use App\Application\QuickBooks\OrderSync\Enums\SkipReason;
use App\Application\QuickBooks\OrderSync\Transformers\DocumentDraftTransformer;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;

/**
 * Hand-computed lines and shipping under COR-295 D16 and D8, one test per shape.
 */
#[CoversClass(DocumentDraftTransformer::class)]
final class DocumentDraftGoldenTest extends TestCase
{
    #[Test]
    public function ireland_zero_rated_goods(): void
    {
        $core = self::core(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(unitPrice: '10.000000', unitPriceVat: '0.000000', vatRate: '0.0')],
            delivery: SyncOrderFixture::contact(country: 'Ireland'),
        ));

        self::assertGoodsLine($core, 0, '10.0000000', 1, '10.00', TaxKey::EuGoods);
        self::assertNull($core->shippingLine);
    }

    #[Test]
    public function ireland_zero_rated_shipping(): void
    {
        $core = self::core(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(unitPrice: '10.000000', unitPriceVat: '0.000000', vatRate: '0.0')],
            delivery: SyncOrderFixture::contact(country: 'Ireland'),
            shippingTotalNet: '5.000000',
            shippingVatRate: '0.0',
        ));

        self::assertShippingLine($core, '5.00', TaxKey::EuShipping);
    }

    #[Test]
    public function ireland_standard_rated_goods(): void
    {
        $core = self::core(SyncOrderFixture::order(
            delivery: SyncOrderFixture::contact(country: 'Ireland'),
            shippingTotalNet: '4.000000',
        ));

        self::assertGoodsLine($core, 0, '8.3333333', 1, '8.33', TaxKey::Standard);
        self::assertShippingLine($core, '4.00', TaxKey::Standard);
    }

    #[Test]
    public function zero_rated_goods(): void
    {
        $core = self::core(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(unitPrice: '12.500000', unitPriceVat: '0.000000', vatRate: '0.0')],
        ));

        self::assertGoodsLine($core, 0, '12.5000000', 1, '12.50', TaxKey::Zero);
    }

    #[Test]
    public function multi_quantity_rounds_the_unit_price_to_seven_places_before_multiplying(): void
    {
        $core = self::core(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(unitPrice: '4.160000', unitPriceVat: '0.830000', quantity: 3)],
        ));

        self::assertGoodsLine($core, 0, '4.1583333', 3, '12.47', TaxKey::Standard);
    }

    #[Test]
    public function discounted_shipping_posts_the_post_discount_net_at_two_places(): void
    {
        $core = self::core(SyncOrderFixture::order(shippingTotalNet: '4.165000'));

        self::assertShippingLine($core, '4.17', TaxKey::Standard);
    }

    #[Test]
    public function refunded_order_posts_its_original_value(): void
    {
        $core = self::core(SyncOrderFixture::order(
            lines: [SyncOrderFixture::line(unitPrice: '20.000000', unitPriceVat: '4.000000', quantity: 2)],
            shippingTotalNet: '3.500000',
        ));

        self::assertGoodsLine($core, 0, '20.0000000', 2, '40.00', TaxKey::Standard);
        self::assertShippingLine($core, '3.50', TaxKey::Standard);
    }

    #[Test]
    public function part_refunded_order_posts_every_original_line(): void
    {
        $core = self::core(SyncOrderFixture::order(
            lines: [
                SyncOrderFixture::line(),
                SyncOrderFixture::line(unitPrice: '4.160000', unitPriceVat: '0.830000', quantity: 3, sku: 'ALZ-002'),
            ],
        ));

        self::assertCount(2, $core->goodsLines);
        self::assertGoodsLine($core, 0, '8.3333333', 1, '8.33', TaxKey::Standard);
        self::assertGoodsLine($core, 1, '4.1583333', 3, '12.47', TaxKey::Standard);
    }

    #[Test]
    public function cancelled_order_is_skipped(): void
    {
        $outcome = DocumentDraftTransformer::build(SyncOrderFixture::order(cancelled: true), DocumentRunLabelsDTO::none());

        self::assertSame(SkipReason::Cancelled, $outcome->skipReason);
        self::assertNull($outcome->draft);
    }

    #[Test]
    public function zero_line_order_fails(): void
    {
        $outcome = DocumentDraftTransformer::build(SyncOrderFixture::order(lines: []), DocumentRunLabelsDTO::none());

        self::assertSame(FailReason::ZeroLines, $outcome->failReason);
        self::assertNull($outcome->draft);
    }

    #[Test]
    public function credit_payment_method_posts_an_invoice(): void
    {
        $outcome = DocumentDraftTransformer::build(
            SyncOrderFixture::order(paymentMethod: RawPaymentMethod::Credit),
            DocumentRunLabelsDTO::none(),
        );

        self::assertSame(BuildStatus::Built, $outcome->status);
        self::assertSame(DocType::Invoice, $outcome->draft?->docType());
    }

    #[Test]
    public function colons_and_semicolons_in_names_lose_only_their_first_occurrence(): void
    {
        $customer = self::core(SyncOrderFixture::order(
            billing: SyncOrderFixture::contact(name: 'Jo:Ann Sm;ith:son;'),
        ))->customer;

        self::assertSame('JoAnn Smith:son;', $customer->displayName);
        self::assertSame('JoAnn', $customer->givenName);
        self::assertSame('Smithson;', $customer->familyName);
    }

    private static function core(SyncOrderDTO $order): DocumentCoreDTO
    {
        $draft = DocumentDraftTransformer::build($order, DocumentRunLabelsDTO::none())->draft;
        self::assertNotNull($draft);

        return $draft->core;
    }

    private static function assertGoodsLine(
        DocumentCoreDTO $core,
        int $index,
        string $unitPrice,
        int $quantity,
        string $amount,
        TaxKey $taxKey,
    ): void {
        $line = $core->goodsLines[$index] ?? null;
        self::assertNotNull($line);
        self::assertSame($unitPrice, $line->unitPrice->toString());
        self::assertSame($quantity, $line->quantity);
        self::assertSame($amount, $line->amount->toString());
        self::assertSame($taxKey, $line->taxKey);
    }

    private static function assertShippingLine(DocumentCoreDTO $core, string $amount, TaxKey $taxKey): void
    {
        self::assertSame($amount, $core->shippingLine?->amount->toString());
        self::assertSame($taxKey, $core->shippingLine?->taxKey);
    }
}
