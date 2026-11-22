<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;
use App\Domain\ValueObjects\TaxRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupportedVatRate::class)]
final class SupportedVatRateTest extends TestCase
{
    /**
     * @return iterable<string, array{float, SupportedVatRate}>
     */
    public static function supportedRates(): iterable
    {
        yield '20' => [20, SupportedVatRate::Standard];
        yield '20.0' => [20.0, SupportedVatRate::Standard];
        yield '0' => [0, SupportedVatRate::Zero];
        yield '0.0' => [0.0, SupportedVatRate::Zero];
    }

    #[Test]
    #[DataProvider('supportedRates')]
    public function it_matches_a_supported_rate_by_percentage(float $percentage, SupportedVatRate $expected): void
    {
        self::assertSame($expected, SupportedVatRate::fromTaxRate(TaxRate::fromPercentage($percentage), '10042'));
    }

    /**
     * @return iterable<string, array{float|null}>
     */
    public static function unsupportedRates(): iterable
    {
        yield 'fractional' => [20.5];
        yield 'tiny fraction above standard' => [20.0001];
        yield 'tiny fraction above zero' => [0.0001];
        yield 'other whole rate' => [5.0];
        yield 'old standard rate' => [17.5];
        yield 'null' => [null];
    }

    #[Test]
    #[DataProvider('unsupportedRates')]
    public function it_throws_for_an_unsupported_rate_with_the_order_and_rate(?float $percentage): void
    {
        $rate = $percentage === null ? null : TaxRate::fromPercentage($percentage);

        try {
            SupportedVatRate::fromTaxRate($rate, '10042');
        } catch (UnsupportedVatRateException $exception) {
            self::assertSame(['order_reference' => '10042', 'rate' => $percentage], $exception->context());

            return;
        }

        self::fail('Expected an unsupported VAT rate exception');
    }
}
