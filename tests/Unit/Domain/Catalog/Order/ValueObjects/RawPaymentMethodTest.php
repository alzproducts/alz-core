<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RawPaymentMethod::class)]
final class RawPaymentMethodTest extends TestCase
{
    /**
     * @return iterable<string, array{RawPaymentMethod, PaymentMethod}>
     */
    public static function mappings(): iterable
    {
        yield 'Admin Order' => [RawPaymentMethod::AdminOrder, PaymentMethod::Admin];
        yield 'PayPal' => [RawPaymentMethod::PayPal, PaymentMethod::PayPal];
        yield 'Credit' => [RawPaymentMethod::Credit, PaymentMethod::Credit];
        yield 'Offline' => [RawPaymentMethod::Offline, PaymentMethod::Unknown];
        yield 'Opayo Hosted' => [RawPaymentMethod::OpayoHosted, PaymentMethod::Card];
        yield 'Opayo Direct' => [RawPaymentMethod::OpayoDirect, PaymentMethod::Card];
        yield 'Opayo Form' => [RawPaymentMethod::OpayoForm, PaymentMethod::Card];
        yield 'Sagepay Direct' => [RawPaymentMethod::SagepayDirect, PaymentMethod::Card];
        yield 'Sagepay Form' => [RawPaymentMethod::SagepayForm, PaymentMethod::Card];
        yield 'Sagepay Hosted' => [RawPaymentMethod::SagepayHosted, PaymentMethod::Card];
        yield 'ShopWired Payments' => [RawPaymentMethod::ShopWiredPayments, PaymentMethod::Card];
    }

    #[Test]
    #[DataProvider('mappings')]
    public function each_processor_string_maps_to_its_payment_method_bucket(RawPaymentMethod $raw, PaymentMethod $expected): void
    {
        self::assertSame($expected, $raw->toPaymentMethod());
    }

    #[Test]
    public function every_case_has_a_mapping_row(): void
    {
        $covered = \array_map(static fn(array $row): RawPaymentMethod => $row[0], \iterator_to_array(self::mappings(), false));

        self::assertSame(RawPaymentMethod::cases(), $covered);
    }
}
