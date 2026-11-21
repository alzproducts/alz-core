<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Transformers;

use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Resolvers\ZapTextResolver;
use App\Domain\QuickBooks\OrderSync\ValueObjects\CustomerDraft;
use App\Domain\QuickBooks\OrderSync\ValueObjects\DocumentRunLabels;
use App\Domain\QuickBooks\OrderSync\ValueObjects\StructuredAddress;
use App\Domain\QuickBooks\OrderSync\ValueObjects\SyncOrder;

final class CustomerDraftTransformer
{
    public static function build(SyncOrder $order, DocType $docType, DocumentRunLabels $labels): CustomerDraft
    {
        $billing = $order->billing;
        // intentional: split before cleaning, so each token loses its own first `:` and `;` (Zap parity).
        $nameTokens = \explode(' ', $billing->name);

        return new CustomerDraft(
            self::displayName($order, $docType) . $labels->customerNameSuffix,
            ZapTextResolver::removeInvalidChars($nameTokens[0]),
            ZapTextResolver::removeInvalidChars($nameTokens[1] ?? ''),
            $billing->company,
            $order->billingEmail,
            $order->billingPhone,
            StructuredAddress::fromContact($billing),
        );
    }

    private static function displayName(SyncOrder $order, DocType $docType): string
    {
        $billing = $order->billing;
        $companyOrName = ZapTextResolver::removeInvalidChars($billing->company !== '' ? $billing->company : $billing->name);

        return match ($docType) {
            DocType::Invoice => $companyOrName,
            DocType::SalesReceipt => $companyOrName . ', ' . $billing->postcode,
        };
    }
}
