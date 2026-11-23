<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ShippingLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use DateTimeImmutable;

/**
 * Invoice and sales receipt drafts with two goods lines (SKU-A standard, SKU-B zero) and a standard-rated shipping line,
 * and refs that resolve all of them.
 */
final class DocumentDraftFixture
{
    public static function core(bool $shipping = true, string $billEmail = 'jo@example.test'): DocumentCoreDTO
    {
        $address = self::address('1 High St', 'Flat 2', 'Leeds', 'LS1 1AA', 'GB');

        return new DocumentCoreDTO(
            docNumber: 'SW-1001',
            txnDate: new DateTimeImmutable('2026-11-20 14:30:00'),
            billEmail: $billEmail,
            customer: new CustomerDraftDTO('Jo Bloggs', 'Jo', 'Bloggs', '', 'jo@example.test', '', $address),
            goodsLines: [
                new GoodsLineDraftDTO(Sku::fromString('SKU-A'), 'Widget', DecimalMoney::fromString('10.00'), 2, DecimalMoney::fromString('20.00'), TaxKey::Standard),
                new GoodsLineDraftDTO(Sku::fromString('SKU-B'), '', DecimalMoney::fromString('5.50'), 1, DecimalMoney::fromString('5.50'), TaxKey::Zero),
            ],
            shippingLine: $shipping ? new ShippingLineDraftDTO(DecimalMoney::fromString('4.99'), TaxKey::Standard) : null,
        );
    }

    public static function invoice(string $poNumber = 'PO-77', string $shipVia = 'Royal Mail', bool $shipping = true): InvoiceDraftDTO
    {
        return new InvoiceDraftDTO(self::core($shipping), $poNumber, $shipVia, "Jo Bloggs\n1 High St\nLeeds", '');
    }

    public static function receipt(?PaymentKey $paymentKey = PaymentKey::PayPal, string $transactionId = 'TXN-9'): SalesReceiptDraftDTO
    {
        return new SalesReceiptDraftDTO(
            self::core(),
            'PAY-REF-1',
            self::address('1 High St', '', 'Leeds', 'LS1 1AA', 'GB'),
            self::address('', '', '', '', ''),
            $transactionId,
            $paymentKey,
        );
    }

    private static function address(string $line1, string $line2, string $city, string $postcode, string $country): StructuredAddressDTO
    {
        return StructuredAddressDTO::fromContact(new OrderContactDTO('', '', $line1, $line2, '', $city, $postcode, $country));
    }

    /**
     * @param array<string, non-empty-string|null> $overrides named-argument overrides; a null value drops the ref
     * @param list<string>                         $skus      the SKUs QBO finds
     */
    public static function refs(array $overrides = [], array $skus = ['SKU-A', 'SKU-B'], ?array $taxCodes = null, ?array $fixedRefs = null): ResolvedDocumentRefsDTO
    {
        $found = \array_map(static fn(string $sku): array => ['name' => $sku, 'id' => 'item-' . $sku], $skus);
        $values = [
            'termsId' => 'terms-1',
            'paymentMethodId' => 'pm-1',
            'depositAccountId' => 'acct-1',
            'poNumberFieldId' => '1',
            'transactionIdFieldId' => '2',
            ...$overrides,
        ];

        return new ResolvedDocumentRefsDTO(
            customerId: 'cust-1',
            items: ItemLookupResult::fromMatches([Sku::fromString('SKU-A'), Sku::fromString('SKU-B')], $found),
            taxCodeIds: $taxCodes ?? [TaxKey::Standard->value => 'tax-std', TaxKey::Zero->value => 'tax-zero'],
            fixedRefIds: $fixedRefs ?? [FixedRefKey::Department->value => 'dept-1', FixedRefKey::SalesClass->value => 'class-1'],
            termsId: $values['termsId'],
            paymentMethodId: $values['paymentMethodId'],
            depositAccountId: $values['depositAccountId'],
            poNumberFieldId: $values['poNumberFieldId'],
            transactionIdFieldId: $values['transactionIdFieldId'],
        );
    }
}
