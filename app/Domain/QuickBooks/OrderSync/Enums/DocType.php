<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;

enum DocType: string
{
    case Invoice = 'invoice';
    case SalesReceipt = 'sales_receipt';

    /**
     * The Zap's path split. intentional: Credit takes the invoice path although the Zap posts nothing for it (COR-321).
     */
    public static function forPaymentMethod(RawPaymentMethod $method): self
    {
        return match ($method) {
            RawPaymentMethod::AdminOrder,
            RawPaymentMethod::Offline,
            RawPaymentMethod::Credit => self::Invoice,
            RawPaymentMethod::PayPal,
            RawPaymentMethod::OpayoHosted,
            RawPaymentMethod::OpayoDirect,
            RawPaymentMethod::OpayoForm,
            RawPaymentMethod::SagepayDirect,
            RawPaymentMethod::SagepayForm,
            RawPaymentMethod::SagepayHosted,
            RawPaymentMethod::ShopWiredPayments => self::SalesReceipt,
        };
    }
}
