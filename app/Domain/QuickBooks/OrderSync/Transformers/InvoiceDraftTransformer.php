<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Transformers;

use App\Domain\QuickBooks\OrderSync\Resolvers\ZapTextResolver;
use App\Domain\QuickBooks\OrderSync\ValueObjects\DocumentCore;
use App\Domain\QuickBooks\OrderSync\ValueObjects\InvoiceDraft;
use App\Domain\QuickBooks\OrderSync\ValueObjects\OrderContact;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrder;

final class InvoiceDraftTransformer
{
    public static function build(SyncOrder $order, DocumentCore $core): InvoiceDraft
    {
        $billing = $order->billing;
        $delivery = $order->delivery;

        return new InvoiceDraft(
            $core,
            ZapTextResolver::poNumber($order->comments),
            ZapTextResolver::shipVia($order->shippingMethod),
            self::addressText([$billing->name, $billing->company], $billing),
            // intentional: the delivery address puts the company before the name, unlike billing (Zap parity).
            self::addressText([$delivery->company, $delivery->name], $delivery),
        );
    }

    /**
     * @param array{string, string} $heading
     */
    private static function addressText(array $heading, OrderContact $contact): string
    {
        return \implode("\n", [
            ...$heading,
            $contact->line1,
            $contact->line2,
            $contact->line3,
            $contact->city . ', ' . $contact->postcode,
            $contact->country,
        ]);
    }
}
