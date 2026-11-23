<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use Webmozart\Assert\Assert;

/**
 * The realm-specific QBO ids a document draft posts against, resolved before the create.
 */
final readonly class ResolvedDocumentRefsDTO
{
    /**
     * @param array<string, non-empty-string>    $taxCodeIds  keyed by TaxKey value
     * @param array<string, non-empty-string>    $fixedRefIds keyed by FixedRefKey value
     * @param non-empty-string|null              $termsId
     * @param non-empty-string|null              $paymentMethodId
     * @param non-empty-string|null              $depositAccountId
     * @param non-empty-string|null              $poNumberFieldId      the P.O. Number custom field's DefinitionId
     * @param non-empty-string|null              $transactionIdFieldId the Transaction ID custom field's DefinitionId
     */
    public function __construct(
        public string $customerId,
        private ItemLookupResult $items,
        private array $taxCodeIds,
        private array $fixedRefIds,
        public ?string $termsId = null,
        public ?string $paymentMethodId = null,
        public ?string $depositAccountId = null,
        public ?string $poNumberFieldId = null,
        public ?string $transactionIdFieldId = null,
    ) {
        Assert::stringNotEmpty($customerId, 'A document posts against a customer id');
    }

    /**
     * @return non-empty-string|null
     */
    public function itemIdFor(Sku $sku): ?string
    {
        return $this->items->itemIdFor($sku);
    }

    /**
     * @return non-empty-string|null
     */
    public function taxCodeIdFor(TaxKey $key): ?string
    {
        return $this->taxCodeIds[$key->value] ?? null;
    }

    /**
     * @return non-empty-string|null
     */
    public function fixedRefIdFor(FixedRefKey $key): ?string
    {
        return $this->fixedRefIds[$key->value] ?? null;
    }

    /**
     * Names each ref the draft needs and these refs lack, e.g. `item:<sku>`, `tax_code:<key>`, `terms`.
     *
     * @return list<string>
     */
    public function missingFor(InvoiceDraftDTO|SalesReceiptDraftDTO $draft): array
    {
        $missing = [...$this->missingLineRefs($draft->core), ...$this->missingFixedRefs($draft->fixedRefKeys())];

        if ($draft instanceof InvoiceDraftDTO) {
            return [...$missing, ...self::absent(['terms' => $this->termsId, 'po_number_field' => $this->poNumberFieldId])];
        }

        $missing = [...$missing, ...self::absent(['transaction_id_field' => $this->transactionIdFieldId])];

        return $draft->paymentKey === null
            ? $missing
            : [...$missing, ...self::absent(['payment_method' => $this->paymentMethodId, 'deposit_account' => $this->depositAccountId])];
    }

    /**
     * @return list<string>
     */
    private function missingLineRefs(DocumentCoreDTO $core): array
    {
        $missing = [];
        foreach ($core->goodsLines as $line) {
            if ($this->itemIdFor($line->itemSku) === null) {
                $missing['item:' . $line->itemSku->value] = true;
            }
        }

        foreach (self::taxKeys($core) as $taxKey) {
            if ($this->taxCodeIdFor($taxKey) === null) {
                $missing['tax_code:' . $taxKey->value] = true;
            }
        }

        return \array_map(strval(...), \array_keys($missing));
    }

    /**
     * @return list<TaxKey>
     */
    private static function taxKeys(DocumentCoreDTO $core): array
    {
        $keys = \array_map(static fn(GoodsLineDraftDTO $line): TaxKey => $line->taxKey, $core->goodsLines);

        return $core->shippingLine === null ? $keys : [...$keys, $core->shippingLine->taxKey];
    }

    /**
     * @param list<FixedRefKey> $keys
     *
     * @return list<string>
     */
    private function missingFixedRefs(array $keys): array
    {
        $missing = \array_filter($keys, fn(FixedRefKey $key): bool => $this->fixedRefIdFor($key) === null);

        return \array_values(\array_map(static fn(FixedRefKey $key): string => 'fixed_ref:' . $key->value, $missing));
    }

    /**
     * @param array<string, string|null> $refs
     *
     * @return list<string>
     */
    private static function absent(array $refs): array
    {
        return \array_keys(\array_filter($refs, static fn(?string $id): bool => $id === null));
    }
}
