<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;

/**
 * Semantic key a sales receipt's deposit account and payment method resolve from (ADR-0018).
 */
enum PaymentKey: string
{
    case PayPal = 'paypal';
    case CardOpayo = 'card_opayo';
    case ShopWiredPayments = 'shopwired_payments';

    public static function forPaymentMethod(RawPaymentMethod $method): ?self
    {
        return match ($method) {
            RawPaymentMethod::PayPal => self::PayPal,
            RawPaymentMethod::OpayoHosted,
            RawPaymentMethod::OpayoDirect,
            RawPaymentMethod::OpayoForm => self::CardOpayo,
            RawPaymentMethod::ShopWiredPayments => self::ShopWiredPayments,
            // intentional: Sagepay gets no key, so its receipts post empty refs (Zap parity; fix is COR-321 point 12).
            RawPaymentMethod::SagepayDirect,
            RawPaymentMethod::SagepayForm,
            RawPaymentMethod::SagepayHosted,
            RawPaymentMethod::AdminOrder,
            RawPaymentMethod::Offline,
            RawPaymentMethod::Credit => null,
        };
    }
}
