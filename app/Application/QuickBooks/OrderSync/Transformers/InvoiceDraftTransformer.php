<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\DocumentFieldTextResolver;

final class InvoiceDraftTransformer
{
    public static function build(SyncOrderDTO $order, DocumentCoreDTO $core): InvoiceDraftDTO
    {
        $billing = $order->billing;
        $delivery = $order->delivery;

        return new InvoiceDraftDTO(
            $core,
            DocumentFieldTextResolver::poNumber($order->comments),
            DocumentFieldTextResolver::shipVia($order->shippingMethod),
            DocumentFieldTextResolver::addressText([$billing->name, $billing->company], $billing),
            // intentional: the delivery address puts the company before the name, unlike billing (Zap parity).
            DocumentFieldTextResolver::addressText([$delivery->company, $delivery->name], $delivery),
        );
    }
}
