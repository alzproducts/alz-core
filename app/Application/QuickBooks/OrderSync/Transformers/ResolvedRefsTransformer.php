<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Transformers;

use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerMatchDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;

/**
 * Gathers the QBO ids a draft can post against; what it lacks is left for `missingFor()` to name.
 */
final class ResolvedRefsTransformer
{
    public static function build(
        InvoiceDraftDTO|SalesReceiptDraftDTO $draft,
        CustomerMatchDTO $customer,
        ItemLookupResult $items,
        ResolvedMappingRefsDTO $mappings,
    ): ResolvedDocumentRefsDTO {
        $paymentKey = $draft instanceof SalesReceiptDraftDTO ? $draft->paymentKey : null;

        return new ResolvedDocumentRefsDTO(
            customerId: $customer->id,
            items: $items,
            taxCodeIds: self::taxCodeIds($mappings),
            fixedRefIds: self::fixedRefIds($mappings),
            termsId: $draft instanceof InvoiceDraftDTO ? self::termsId($draft, $customer, $mappings) : null,
            paymentMethodId: $paymentKey === null ? null : $mappings->paymentMethod($paymentKey),
            depositAccountId: $paymentKey === null ? null : $mappings->depositAccount($paymentKey),
            poNumberFieldId: $mappings->poNumberField(),
            transactionIdFieldId: $mappings->transactionIdField(),
        );
    }

    /**
     * @return non-empty-string|null
     */
    private static function termsId(InvoiceDraftDTO $draft, CustomerMatchDTO $customer, ResolvedMappingRefsDTO $mappings): ?string
    {
        $defaultKey = $draft->defaultTermsKeyFor($customer->salesTermRef);

        return $defaultKey === null ? $customer->salesTermRef : $mappings->fixedRef($defaultKey);
    }

    /**
     * @return array<string, non-empty-string>
     */
    private static function taxCodeIds(ResolvedMappingRefsDTO $mappings): array
    {
        $ids = [];
        foreach (TaxKey::cases() as $key) {
            $id = $mappings->tax($key);
            if ($id !== null) {
                $ids[$key->value] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, non-empty-string>
     */
    private static function fixedRefIds(ResolvedMappingRefsDTO $mappings): array
    {
        $ids = [];
        foreach (FixedRefKey::cases() as $key) {
            $id = $mappings->fixedRef($key);
            if ($id !== null) {
                $ids[$key->value] = $id;
            }
        }

        return $ids;
    }
}
