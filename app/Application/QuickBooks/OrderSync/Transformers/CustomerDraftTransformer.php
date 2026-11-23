<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\DocumentFieldTextResolver;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;

final class CustomerDraftTransformer
{
    public static function build(SyncOrderDTO $order, DocType $docType, DocumentRunLabelsDTO $labels): CustomerDraftDTO
    {
        $billing = $order->billing;
        // intentional: split before cleaning, so each token loses its own first `:` and `;` (Zap parity).
        $nameTokens = \explode(' ', $billing->name);

        return new CustomerDraftDTO(
            self::displayName($order, $docType) . $labels->customerNameSuffix,
            DocumentFieldTextResolver::removeInvalidChars($nameTokens[0]),
            DocumentFieldTextResolver::removeInvalidChars($nameTokens[1] ?? ''),
            $billing->company,
            $order->billingEmail,
            $order->billingPhone,
            StructuredAddressDTO::fromContact($billing),
        );
    }

    private static function displayName(SyncOrderDTO $order, DocType $docType): string
    {
        $billing = $order->billing;
        $companyOrName = DocumentFieldTextResolver::removeInvalidChars($billing->company !== '' ? $billing->company : $billing->name);

        return match ($docType) {
            DocType::Invoice => $companyOrName,
            DocType::SalesReceipt => $companyOrName . ', ' . $billing->postcode,
        };
    }
}
