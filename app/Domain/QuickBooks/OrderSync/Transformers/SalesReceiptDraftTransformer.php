<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Transformers;

use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Resolvers\ZapTextResolver;
use App\Domain\QuickBooks\OrderSync\ValueObjects\DocumentCore;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SalesReceiptDraft;
use App\Domain\QuickBooks\OrderSync\ValueObjects\StructuredAddress;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrder;

final class SalesReceiptDraftTransformer
{
    public static function build(SyncOrder $order, DocumentCore $core): SalesReceiptDraft
    {
        return new SalesReceiptDraft(
            $core,
            'A' . $order->reference,
            StructuredAddress::fromContact($order->billing),
            StructuredAddress::fromContact($order->delivery),
            ZapTextResolver::transactionId($order->transactionId),
            PaymentKey::forPaymentMethod($order->paymentMethod),
        );
    }
}
