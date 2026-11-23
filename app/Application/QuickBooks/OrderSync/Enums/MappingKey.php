<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;

/**
 * The seeded mapping keys; the factories are the only route from a semantic key to its row (ADR-0018).
 */
enum MappingKey: string
{
    case TaxStandard = 'tax.standard';
    case TaxZero = 'tax.zero';
    case TaxEuGoods = 'tax.eu_goods';
    case TaxEuShipping = 'tax.eu_shipping';
    case DepositAccountPayPal = 'deposit_account.paypal';
    case DepositAccountCardOpayo = 'deposit_account.card_opayo';
    case DepositAccountShopWiredPayments = 'deposit_account.shopwired_payments';
    case PaymentMethodPayPal = 'payment_method.paypal';
    case PaymentMethodCardOpayo = 'payment_method.card_opayo';
    case PaymentMethodShopWiredPayments = 'payment_method.shopwired_payments';
    case Department = 'department';
    case SalesClass = 'class';
    case DefaultTerms = 'default_terms';
    case PoNumberField = 'custom_field.po_number';
    case TransactionIdField = 'custom_field.transaction_id';

    public static function forTax(TaxKey $key): self
    {
        return match ($key) {
            TaxKey::Standard => self::TaxStandard,
            TaxKey::Zero => self::TaxZero,
            TaxKey::EuGoods => self::TaxEuGoods,
            TaxKey::EuShipping => self::TaxEuShipping,
        };
    }

    public static function forFixedRef(FixedRefKey $key): self
    {
        return match ($key) {
            FixedRefKey::Department => self::Department,
            FixedRefKey::SalesClass => self::SalesClass,
            FixedRefKey::DefaultTerms => self::DefaultTerms,
        };
    }

    public static function forDepositAccount(PaymentKey $key): self
    {
        return match ($key) {
            PaymentKey::PayPal => self::DepositAccountPayPal,
            PaymentKey::CardOpayo => self::DepositAccountCardOpayo,
            PaymentKey::ShopWiredPayments => self::DepositAccountShopWiredPayments,
        };
    }

    public static function forPaymentMethod(PaymentKey $key): self
    {
        return match ($key) {
            PaymentKey::PayPal => self::PaymentMethodPayPal,
            PaymentKey::CardOpayo => self::PaymentMethodCardOpayo,
            PaymentKey::ShopWiredPayments => self::PaymentMethodShopWiredPayments,
        };
    }

    public function entity(): MappingEntity
    {
        return match ($this) {
            self::TaxStandard, self::TaxZero, self::TaxEuGoods, self::TaxEuShipping => MappingEntity::TaxCode,
            self::DepositAccountPayPal,
            self::DepositAccountCardOpayo,
            self::DepositAccountShopWiredPayments => MappingEntity::Account,
            self::PaymentMethodPayPal,
            self::PaymentMethodCardOpayo,
            self::PaymentMethodShopWiredPayments => MappingEntity::PaymentMethod,
            self::Department => MappingEntity::Department,
            self::SalesClass => MappingEntity::SalesClass,
            self::DefaultTerms => MappingEntity::Term,
            self::PoNumberField, self::TransactionIdField => MappingEntity::CustomField,
        };
    }
}
