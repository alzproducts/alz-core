<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\Mappings\DTOs;

use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedMappingRefsDTO::class)]
#[CoversClass(MappingKey::class)]
final class ResolvedMappingRefsDTOTest extends TestCase
{
    #[Test]
    public function each_accessor_reads_its_own_key(): void
    {
        $refIds = [];
        foreach (MappingKey::cases() as $key) {
            $refIds[$key->value] = 'id:' . $key->value;
        }
        $refs = new ResolvedMappingRefsDTO($refIds);

        $this->assertSame(
            ['id:tax.standard', 'id:tax.zero', 'id:tax.eu_goods', 'id:tax.eu_shipping'],
            \array_map($refs->tax(...), [TaxKey::Standard, TaxKey::Zero, TaxKey::EuGoods, TaxKey::EuShipping]),
        );
        $this->assertSame(
            ['id:department', 'id:class', 'id:default_terms'],
            \array_map($refs->fixedRef(...), [FixedRefKey::Department, FixedRefKey::SalesClass, FixedRefKey::DefaultTerms]),
        );
        $payments = [PaymentKey::PayPal, PaymentKey::CardOpayo, PaymentKey::ShopWiredPayments];
        $this->assertSame(
            ['id:deposit_account.paypal', 'id:deposit_account.card_opayo', 'id:deposit_account.shopwired_payments'],
            \array_map($refs->depositAccount(...), $payments),
        );
        $this->assertSame(
            ['id:payment_method.paypal', 'id:payment_method.card_opayo', 'id:payment_method.shopwired_payments'],
            \array_map($refs->paymentMethod(...), $payments),
        );
        $this->assertSame('id:custom_field.po_number', $refs->poNumberField());
        $this->assertSame('id:custom_field.transaction_id', $refs->transactionIdField());
    }

    #[Test]
    public function an_unresolved_key_reads_as_null(): void
    {
        $refs = new ResolvedMappingRefsDTO(['tax.zero' => '7']);

        $this->assertSame('7', $refs->tax(TaxKey::Zero));
        $this->assertNull($refs->tax(TaxKey::Standard));
        $this->assertNull($refs->fixedRef(FixedRefKey::DefaultTerms));
        $this->assertNull($refs->depositAccount(PaymentKey::PayPal));
        $this->assertNull($refs->paymentMethod(PaymentKey::PayPal));
        $this->assertNull($refs->poNumberField());
        $this->assertNull($refs->transactionIdField());
    }
}
