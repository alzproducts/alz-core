<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocType::class)]
#[CoversClass(PaymentKey::class)]
final class DocumentRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{RawPaymentMethod, DocType, PaymentKey|null}>
     */
    public static function routes(): iterable
    {
        yield 'Admin Order' => [RawPaymentMethod::AdminOrder, DocType::Invoice, null];
        yield 'Offline' => [RawPaymentMethod::Offline, DocType::Invoice, null];
        yield 'Credit' => [RawPaymentMethod::Credit, DocType::Invoice, null];
        yield 'PayPal' => [RawPaymentMethod::PayPal, DocType::SalesReceipt, PaymentKey::PayPal];
        yield 'Opayo Hosted' => [RawPaymentMethod::OpayoHosted, DocType::SalesReceipt, PaymentKey::CardOpayo];
        yield 'Opayo Direct' => [RawPaymentMethod::OpayoDirect, DocType::SalesReceipt, PaymentKey::CardOpayo];
        yield 'Opayo Form' => [RawPaymentMethod::OpayoForm, DocType::SalesReceipt, PaymentKey::CardOpayo];
        yield 'Sagepay Direct' => [RawPaymentMethod::SagepayDirect, DocType::SalesReceipt, null];
        yield 'Sagepay Form' => [RawPaymentMethod::SagepayForm, DocType::SalesReceipt, null];
        yield 'Sagepay Hosted' => [RawPaymentMethod::SagepayHosted, DocType::SalesReceipt, null];
        yield 'ShopWired Payments' => [
            RawPaymentMethod::ShopWiredPayments,
            DocType::SalesReceipt,
            PaymentKey::ShopWiredPayments,
        ];
    }

    #[Test]
    #[DataProvider('routes')]
    public function it_routes_each_payment_method_to_a_doc_type_and_payment_key(
        RawPaymentMethod $method,
        DocType $docType,
        ?PaymentKey $paymentKey,
    ): void {
        self::assertSame($docType, DocType::forPaymentMethod($method));
        self::assertSame($paymentKey, PaymentKey::forPaymentMethod($method));
    }
}
