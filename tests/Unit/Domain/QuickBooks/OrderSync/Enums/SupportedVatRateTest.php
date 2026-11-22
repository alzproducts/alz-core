<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SupportedVatRate::class)]
final class SupportedVatRateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SupportedVatRate}>
     */
    public static function supportedRates(): iterable
    {
        yield '20' => ['20', SupportedVatRate::Standard];
        yield '20.0' => ['20.0', SupportedVatRate::Standard];
        yield '20.000000' => ['20.000000', SupportedVatRate::Standard];
        yield '0' => ['0', SupportedVatRate::Zero];
        yield '0.0' => ['0.0', SupportedVatRate::Zero];
        yield 'leading zero' => ['020.00', SupportedVatRate::Standard];
    }

    #[Test]
    #[DataProvider('supportedRates')]
    public function it_matches_a_supported_rate_by_decimal_value(string $rate, SupportedVatRate $expected): void
    {
        self::assertSame($expected, SupportedVatRate::fromRate($rate, '10042'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unsupportedRates(): iterable
    {
        yield 'fractional' => ['20.5'];
        yield 'trailing non-zero fraction' => ['20.0001'];
        yield 'negative' => ['-20'];
        yield 'exponent' => ['2e1'];
        yield 'other whole rate' => ['5'];
        yield 'null' => [null];
        yield 'not a number' => ['abc'];
        yield 'trailing text' => ['20abc'];
    }

    #[Test]
    #[DataProvider('unsupportedRates')]
    public function it_throws_for_an_unsupported_rate_with_the_order_and_rate(?string $rate): void
    {
        try {
            SupportedVatRate::fromRate($rate, '10042');
        } catch (UnsupportedVatRateException $exception) {
            self::assertSame(['order_reference' => '10042', 'rate' => $rate], $exception->context());

            return;
        }

        self::fail('Expected an unsupported VAT rate exception');
    }
}
