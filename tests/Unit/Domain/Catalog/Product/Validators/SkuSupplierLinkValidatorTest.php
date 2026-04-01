<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\Validators\SkuSupplierLinkResult;
use App\Domain\Catalog\Product\Validators\SkuSupplierLinkValidator;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkuSupplierLinkValidator::class)]
#[CoversClass(SkuSupplierLinkResult::class)]
final class SkuSupplierLinkValidatorTest extends TestCase
{
    // ========================================================================
    // Validator tests
    // ========================================================================

    #[Test]
    public function it_passes_when_all_skus_have_specified_supplier_linked(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');

        $commands = [
            self::makeCommand($sku1),
            self::makeCommand($sku2),
        ];

        $suppliersBySku = [
            'SKU-001' => [new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true)],
            'SKU-002' => [new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 20.0, isDefault: true)],
        ];

        $result = (new SkuSupplierLinkValidator($commands, 'AcmeCo', $suppliersBySku))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame([], $result->unlinkedSkus());
    }

    #[Test]
    public function it_fails_when_one_sku_lacks_the_supplier(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');

        $commands = [
            self::makeCommand($sku1),
            self::makeCommand($sku2),
        ];

        $suppliersBySku = [
            'SKU-001' => [new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true)],
            'SKU-002' => [],
        ];

        $result = (new SkuSupplierLinkValidator($commands, 'AcmeCo', $suppliersBySku))->validate();

        self::assertTrue($result->failed());

        $unlinkedValues = \array_map(static fn(Sku $s): string => $s->value, $result->unlinkedSkus());
        self::assertSame(['SKU-002'], $unlinkedValues);
    }

    #[Test]
    public function it_fails_when_multiple_skus_lack_the_supplier(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');
        $sku3 = Sku::fromTrusted('SKU-003');

        $commands = [
            self::makeCommand($sku1),
            self::makeCommand($sku2),
            self::makeCommand($sku3),
        ];

        $suppliersBySku = [
            'SKU-001' => [new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true)],
            'SKU-002' => [],
            'SKU-003' => [],
        ];

        $result = (new SkuSupplierLinkValidator($commands, 'AcmeCo', $suppliersBySku))->validate();

        self::assertTrue($result->failed());

        $unlinkedValues = \array_map(static fn(Sku $s): string => $s->value, $result->unlinkedSkus());
        self::assertSame(['SKU-002', 'SKU-003'], $unlinkedValues);
    }

    #[Test]
    public function it_fails_when_sku_has_different_supplier_but_not_target(): void
    {
        $sku = Sku::fromTrusted('SKU-001');

        $commands = [self::makeCommand($sku)];

        $suppliersBySku = [
            'SKU-001' => [new ProductSupplier(supplierName: 'OtherSupplier', purchasePrice: 10.0, isDefault: true)],
        ];

        $result = (new SkuSupplierLinkValidator($commands, 'AcmeCo', $suppliersBySku))->validate();

        self::assertTrue($result->failed());

        $unlinkedValues = \array_map(static fn(Sku $s): string => $s->value, $result->unlinkedSkus());
        self::assertSame(['SKU-001'], $unlinkedValues);
    }

    #[Test]
    public function it_passes_when_sku_has_multiple_suppliers_including_target(): void
    {
        $sku = Sku::fromTrusted('SKU-001');

        $commands = [self::makeCommand($sku)];

        $suppliersBySku = [
            'SKU-001' => [
                new ProductSupplier(supplierName: 'OtherSupplier', purchasePrice: 5.0, isDefault: false),
                new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true),
            ],
        ];

        $result = (new SkuSupplierLinkValidator($commands, 'AcmeCo', $suppliersBySku))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_preserves_order_of_unlinked_skus_from_commands(): void
    {
        $sku1 = Sku::fromTrusted('SKU-A');
        $sku2 = Sku::fromTrusted('SKU-B');
        $sku3 = Sku::fromTrusted('SKU-C');

        $commands = [
            self::makeCommand($sku1),
            self::makeCommand($sku2),
            self::makeCommand($sku3),
        ];

        $suppliersBySku = [
            'SKU-A' => [],
            'SKU-B' => [new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true)],
            'SKU-C' => [],
        ];

        $result = (new SkuSupplierLinkValidator($commands, 'AcmeCo', $suppliersBySku))->validate();

        $unlinkedValues = \array_map(static fn(Sku $s): string => $s->value, $result->unlinkedSkus());
        self::assertSame(['SKU-A', 'SKU-C'], $unlinkedValues);
    }

    // ========================================================================
    // Result tests
    // ========================================================================

    #[Test]
    public function passed_returns_true_when_no_unlinked_skus(): void
    {
        $result = new SkuSupplierLinkResult([], 'AcmeCo');

        self::assertTrue($result->passed());
    }

    #[Test]
    public function failed_returns_true_when_unlinked_skus_present(): void
    {
        $result = new SkuSupplierLinkResult([Sku::fromTrusted('SKU-001')], 'AcmeCo');

        self::assertTrue($result->failed());
    }

    #[Test]
    public function reason_is_empty_string_when_passed(): void
    {
        $result = new SkuSupplierLinkResult([], 'AcmeCo');

        self::assertSame('', $result->reason());
    }

    #[Test]
    public function reason_includes_count_and_supplier_name_when_failed(): void
    {
        $result = new SkuSupplierLinkResult(
            [Sku::fromTrusted('SKU-001'), Sku::fromTrusted('SKU-002')],
            'AcmeCo',
        );

        $reason = $result->reason();

        self::assertStringContainsString('2 SKU(s)', $reason);
        self::assertStringContainsString("'AcmeCo'", $reason);
    }

    #[Test]
    public function context_is_empty_when_passed(): void
    {
        $result = new SkuSupplierLinkResult([], 'AcmeCo');

        self::assertSame([], $result->context());
    }

    #[Test]
    public function context_contains_unlinked_sku_strings_and_supplier_name_when_failed(): void
    {
        $result = new SkuSupplierLinkResult(
            [Sku::fromTrusted('SKU-1'), Sku::fromTrusted('SKU-2')],
            'AcmeCo',
        );

        self::assertSame(
            [
                'unlinked_skus' => ['SKU-1', 'SKU-2'],
                'supplier_name' => 'AcmeCo',
            ],
            $result->context(),
        );
    }

    #[Test]
    public function unlinked_skus_returns_the_sku_list(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');

        $result = new SkuSupplierLinkResult([$sku1, $sku2], 'AcmeCo');

        self::assertSame([$sku1, $sku2], $result->unlinkedSkus());
    }

    #[Test]
    public function or_fail_throws_validation_failed_exception_with_reason_and_context(): void
    {
        $result = new SkuSupplierLinkResult(
            [Sku::fromTrusted('SKU-001'), Sku::fromTrusted('SKU-002')],
            'AcmeCo',
        );

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertSame($result->reason(), $e->reason());
            self::assertSame($result->context(), $e->context());
        }
    }

    #[Test]
    public function or_fail_is_noop_when_passed(): void
    {
        $result = new SkuSupplierLinkResult([], 'AcmeCo');

        // Should not throw
        $result->orFail();

        self::assertTrue($result->passed());
    }

    // ========================================================================
    // Factory helpers
    // ========================================================================

    private static function makeCommand(Sku $sku): UpdateCostPriceCommand
    {
        return new UpdateCostPriceCommand(
            sku: $sku,
            costPrice: Money::exclusive(10.50),
        );
    }
}
