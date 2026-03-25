<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Money\Validators;

use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Money\Validators\VatRoundTripAggregateResult;
use App\Domain\Shared\Money\Validators\VatRoundTripResult;
use App\Domain\Shared\Money\Validators\VatRoundTripValidator;
use App\Domain\ValueObjects\TaxRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VatRoundTripValidator::class)]
#[CoversClass(VatRoundTripResult::class)]
#[CoversClass(VatRoundTripAggregateResult::class)]
final class VatRoundTripValidatorTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Single Validator
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_passes_for_vat_safe_price(): void
    {
        $result = (new VatRoundTripValidator(
            grossAmount: 24.00,
            sku: 'ABC-123',
            field: 'price',
            taxRate: TaxRate::standard(),
        ))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame('', $result->reason());
        self::assertSame([], $result->context());
    }

    #[Test]
    public function it_fails_for_vat_unsafe_price(): void
    {
        $result = (new VatRoundTripValidator(
            grossAmount: 0.03,
            sku: 'ABC-123',
            field: 'price',
            taxRate: TaxRate::standard(),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertFalse($result->passed());
        self::assertStringContainsString('ABC-123', $result->reason());
        self::assertStringContainsString('price', $result->reason());
        self::assertSame('ABC-123', $result->context()['sku']);
        self::assertSame('price', $result->context()['field']);
        self::assertSame(0.03, $result->context()['amount']);
    }

    #[Test]
    public function it_passes_for_zero_amount(): void
    {
        $result = (new VatRoundTripValidator(
            grossAmount: 0.00,
            sku: 'ABC-123',
            field: 'salePrice',
            taxRate: TaxRate::standard(),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function or_fail_throws_on_failure(): void
    {
        $result = (new VatRoundTripValidator(
            grossAmount: 0.09,
            sku: 'DEF-456',
            field: 'salePrice',
            taxRate: TaxRate::standard(),
        ))->validate();

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('DEF-456', $e->reason());
            self::assertSame('salePrice', $e->context()['field']);
        }
    }

    #[Test]
    public function or_fail_is_noop_on_success(): void
    {
        $result = (new VatRoundTripValidator(
            grossAmount: 10.00,
            sku: 'ABC-123',
            field: 'price',
            taxRate: TaxRate::standard(),
        ))->validate();

        $result->orFail();

        self::assertTrue($result->passed());
    }

    /*
    |--------------------------------------------------------------------------
    | Aggregate Result
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function aggregate_passes_when_all_children_pass(): void
    {
        $results = [
            'ABC.price' => $this->validatePrice(10.00, 'ABC', 'price'),
            'DEF.price' => $this->validatePrice(24.00, 'DEF', 'price'),
        ];

        $aggregate = new VatRoundTripAggregateResult($results);

        self::assertTrue($aggregate->passed());
        self::assertFalse($aggregate->failed());
        self::assertSame([], $aggregate->context());
    }

    #[Test]
    public function aggregate_fails_when_any_child_fails(): void
    {
        $results = [
            'ABC.price' => $this->validatePrice(10.00, 'ABC', 'price'),
            'DEF.salePrice' => $this->validatePrice(0.03, 'DEF', 'salePrice'),
        ];

        $aggregate = new VatRoundTripAggregateResult($results);

        self::assertTrue($aggregate->failed());
        self::assertStringContainsString('DEF', $aggregate->reason());

        $context = $aggregate->context();
        self::assertArrayHasKey('DEF.salePrice', $context);
        self::assertSame('DEF', $context['DEF.salePrice']['sku']);
        self::assertSame('salePrice', $context['DEF.salePrice']['field']);
        self::assertSame(0.03, $context['DEF.salePrice']['amount']);
        self::assertArrayNotHasKey('ABC.price', $context);
    }

    #[Test]
    public function aggregate_context_contains_all_failures(): void
    {
        $results = [
            'ABC.price' => $this->validatePrice(0.03, 'ABC', 'price'),
            'DEF.salePrice' => $this->validatePrice(0.09, 'DEF', 'salePrice'),
        ];

        $aggregate = new VatRoundTripAggregateResult($results);

        $context = $aggregate->context();
        self::assertCount(2, $context);
        self::assertArrayHasKey('ABC.price', $context);
        self::assertArrayHasKey('DEF.salePrice', $context);
    }

    #[Test]
    public function aggregate_reason_joins_child_reasons(): void
    {
        $results = [
            'ABC.price' => $this->validatePrice(0.03, 'ABC', 'price'),
            'DEF.salePrice' => $this->validatePrice(0.09, 'DEF', 'salePrice'),
        ];

        $aggregate = new VatRoundTripAggregateResult($results);

        $reason = $aggregate->reason();
        self::assertStringContainsString('ABC', $reason);
        self::assertStringContainsString('DEF', $reason);
        self::assertStringContainsString('; ', $reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function validatePrice(float $amount, string $sku, string $field): VatRoundTripResult
    {
        return (new VatRoundTripValidator(
            grossAmount: $amount,
            sku: $sku,
            field: $field,
            taxRate: TaxRate::standard(),
        ))->validate();
    }
}
