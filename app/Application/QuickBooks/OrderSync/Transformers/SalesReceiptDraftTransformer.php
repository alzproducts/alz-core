<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\DocumentFieldTextResolver;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;

final class SalesReceiptDraftTransformer
{
    public static function build(SyncOrderDTO $order, DocumentCoreDTO $core): SalesReceiptDraftDTO
    {
        return new SalesReceiptDraftDTO(
            $core,
            'A' . $order->reference,
            StructuredAddressDTO::fromContact($order->billing),
            StructuredAddressDTO::fromContact($order->delivery),
            DocumentFieldTextResolver::transactionId($order->transactionId),
            PaymentKey::forPaymentMethod($order->paymentMethod),
        );
    }
}
