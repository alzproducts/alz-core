<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\Enums\FixtureSku;
use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ShippingLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\DomainException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksQueryEscaper;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;

/**
 * One invoice and one sales receipt, found or created under fixed numbers and never voided, and what QBO made of each.
 * The refs are fixed lookups: the sync's resolution rules stay in the sync.
 */
final readonly class QuickBooksSandboxProofFactory
{
    private const int CALL_SECONDS = 120;

    private const array DOC_NUMBERS = [
        DocType::Invoice->value => QuickBooksSandboxMapper::INVOICE_DOC_NUMBER,
        DocType::SalesReceipt->value => QuickBooksSandboxMapper::SALES_RECEIPT_DOC_NUMBER,
    ];

    public function __construct(
        private QuickBooksOrderPostingInterface $posting,
        private QuickBooksMappingRepositoryInterface $mappings,
        private QuickBooksRealmInterface $realm,
        private QuickBooksTransport $transport,
        private QuickBooksTokenService $tokens,
    ) {}

    /**
     * @return array<string, string|null> each proof, with why it failed
     *
     * @throws DomainException
     */
    public function run(): array
    {
        $refs = $this->mappings->resolvedRefs($this->realm->realmId());
        $items = $this->posting->findItems($this->tokens->accessToken(), [self::sku()], self::deadline());
        $found = $this->posting->findDocuments($this->tokens->accessToken(), \array_values(self::DOC_NUMBERS), self::deadline());

        $proofs = [];
        foreach (DocType::cases() as $type) {
            $document = \array_find(
                $found->forDocNumber(self::DOC_NUMBERS[$type->value]),
                static fn(ExistingDocumentDTO $existing): bool => $existing->type === $type,
            ) ?? $this->create($type, $refs, $items);
            $proofs = [...$proofs, ...$this->proofs($type, $document, $items->itemIdFor(self::sku()), $refs->tax(TaxKey::Standard))];
        }

        return $proofs;
    }

    /**
     * @param ExistingDocumentDTO|string $document or why there is none
     *
     * @return array<string, string|null>
     *
     * @throws DomainException
     */
    private function proofs(DocType $type, ExistingDocumentDTO|string $document, ?string $itemId, ?string $taxCodeId): array
    {
        $vat = "{$type->value}: 20% VAT on the goods line";
        $shipping = "{$type->value}: shipping line on SHIPPING_ITEM_ID";
        if (\is_string($document)) {
            return [$vat => $document, $shipping => $document];
        }

        $row = $this->fetch($document);
        if ($row === null) {
            return [$vat => 'the document could not be read', $shipping => 'the document could not be read'];
        }

        $hasVat = $itemId !== null && $taxCodeId !== null && QuickBooksSandboxRowParser::hasStandardVat($row, $itemId, $taxCodeId);

        return [
            $vat => $hasVat ? null : "document {$document->docNumber} does not carry it",
            $shipping => QuickBooksSandboxRowParser::hasShippingLine($row) ? null : "document {$document->docNumber} does not carry it",
        ];
    }

    /**
     * @return ExistingDocumentDTO|string the document, or why there is none
     *
     * @throws DomainException
     */
    private function create(DocType $type, ResolvedMappingRefsDTO $refs, ItemLookupResult $items): ExistingDocumentDTO|string
    {
        $customer = self::customer();
        $resolution = $this->posting
            ->findOrCreateCustomers($this->tokens->accessToken(), [$customer], self::deadline())
            ->forDisplayName($customer->displayName);
        if ($resolution?->match === null) {
            return 'customer ' . ($resolution?->failure->value ?? 'not found') . ', ' . QuickBooksSandboxMapper::faultText($resolution?->fault);
        }

        $document = new PostableDocumentDTO(self::draft($type, $customer), self::refs($resolution->match->id, $refs, $items));
        $outcome = $this->posting->createDocuments($this->tokens->accessToken(), [$document], self::deadline())[0];

        return $outcome->kind === BatchOutcomeKind::Created && $outcome->qboId !== null
            ? new ExistingDocumentDTO(self::DOC_NUMBERS[$type->value], $outcome->qboId, $type)
            : "create {$outcome->kind->value}, " . QuickBooksSandboxMapper::faultText($outcome->fault);
    }

    /**
     * @throws DomainException
     */
    private function fetch(ExistingDocumentDTO $document): ?object
    {
        $entity = match ($document->type) {
            DocType::Invoice => 'Invoice',
            DocType::SalesReceipt => 'SalesReceipt',
        };
        $query = "SELECT * FROM {$entity} WHERE Id = " . QuickBooksQueryEscaper::literal($document->qboId);

        return $this->transport->query($this->tokens->accessToken(), $query, self::deadline())[0] ?? null;
    }

    /**
     * @throws DomainException
     */
    private static function draft(DocType $type, CustomerDraftDTO $customer): InvoiceDraftDTO|SalesReceiptDraftDTO
    {
        $core = self::core($type, $customer);
        $address = $customer->billingAddress;

        return match ($type) {
            DocType::Invoice => new InvoiceDraftDTO($core, 'PO-PROOF', 'Proof Courier', '1 Proof Street', '1 Proof Street'),
            DocType::SalesReceipt => new SalesReceiptDraftDTO($core, 'PAY-PROOF', $address, $address, 'TXN-PROOF', PaymentKey::PayPal),
        };
    }

    /**
     * @throws DomainException
     */
    private static function core(DocType $type, CustomerDraftDTO $customer): DocumentCoreDTO
    {
        $amount = DecimalMoney::fromString('10.00');

        return new DocumentCoreDTO(
            docNumber: self::DOC_NUMBERS[$type->value],
            txnDate: new DateTimeImmutable(),
            billEmail: $customer->email,
            customer: $customer,
            goodsLines: [new GoodsLineDraftDTO(self::sku(), 'Sync proof goods', $amount, 1, $amount, TaxKey::Standard)],
            shippingLine: new ShippingLineDraftDTO(DecimalMoney::fromString('4.99'), TaxKey::Standard),
        );
    }

    private static function refs(string $customerId, ResolvedMappingRefsDTO $refs, ItemLookupResult $items): ResolvedDocumentRefsDTO
    {
        return new ResolvedDocumentRefsDTO(
            customerId: $customerId,
            items: $items,
            taxCodeIds: \array_filter([TaxKey::Standard->value => $refs->tax(TaxKey::Standard)], \is_string(...)),
            fixedRefIds: \array_filter([
                FixedRefKey::Department->value => $refs->fixedRef(FixedRefKey::Department),
                FixedRefKey::SalesClass->value => $refs->fixedRef(FixedRefKey::SalesClass),
            ], \is_string(...)),
            termsId: $refs->fixedRef(FixedRefKey::DefaultTerms),
            paymentMethodId: $refs->paymentMethod(PaymentKey::PayPal),
            depositAccountId: $refs->depositAccount(PaymentKey::PayPal),
            poNumberFieldId: $refs->poNumberField(),
            transactionIdFieldId: $refs->transactionIdField(),
        );
    }

    private static function customer(): CustomerDraftDTO
    {
        $address = StructuredAddressDTO::fromContact(new OrderContactDTO('', '', '1 Proof Street', '', '', 'Leeds', 'LS1 1AA', 'GB'));

        return new CustomerDraftDTO(QuickBooksSandboxMapper::CUSTOMER_NAME, 'Sync', 'Proof', '', 'proof@example.test', '', $address);
    }

    /**
     * @throws DomainException
     */
    private static function sku(): Sku
    {
        return Sku::fromString(FixtureSku::Sku001->value);
    }

    private static function deadline(): Deadline
    {
        return Deadline::after(new DateTimeImmutable(), self::CALL_SECONDS);
    }
}
