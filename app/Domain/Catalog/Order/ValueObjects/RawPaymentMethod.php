<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

/**
 * The exact processor string ShopWired gives an order. A closed set: an order with any
 * other value is rejected at ingest, so a new processor needs a new case here.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
enum RawPaymentMethod: string
{
    case AdminOrder = 'Admin Order';
    case PayPal = 'PayPal';
    case Credit = 'Credit';
    case Offline = 'Offline';
    case OpayoHosted = 'Opayo Hosted';
    case OpayoDirect = 'Opayo Direct';
    case OpayoForm = 'Opayo Form';
    case SagepayDirect = 'Sagepay Direct';
    case SagepayForm = 'Sagepay Form';
    case SagepayHosted = 'Sagepay Hosted';
    case ShopWiredPayments = 'ShopWired Payments';

    public function toPaymentMethod(): PaymentMethod
    {
        return match ($this) {
            self::AdminOrder => PaymentMethod::Admin,
            self::PayPal => PaymentMethod::PayPal,
            self::Credit => PaymentMethod::Credit,
            self::Offline => PaymentMethod::Unknown,
            self::OpayoHosted => PaymentMethod::Card,
            self::OpayoDirect => PaymentMethod::Card,
            self::OpayoForm => PaymentMethod::Card,
            self::SagepayDirect => PaymentMethod::Card,
            self::SagepayForm => PaymentMethod::Card,
            self::SagepayHosted => PaymentMethod::Card,
            self::ShopWiredPayments => PaymentMethod::Card,
        };
    }
}
