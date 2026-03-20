<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Money\Validators;

use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Money\Validators\MoneyEqualsResult;
use App\Domain\Shared\Money\Validators\MoneyEqualsValidator;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoneyEqualsValidator::class)]
#[CoversClass(MoneyEqualsResult::class)]
final class MoneyEqualsValidatorTest extends TestCase
{
    #[Test]
    public function it_passes_when_money_values_are_identical(): void
    {
        $result = (new MoneyEqualsValidator(
            proposed: Money::inclusive(10.00),
            current: Money::inclusive(10.00),
        ))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame('', $result->reason());
        self::assertSame([], $result->context());
    }

    #[Test]
    public function it_fails_when_amounts_differ(): void
    {
        $result = (new MoneyEqualsValidator(
            proposed: Money::inclusive(15.00),
            current: Money::inclusive(10.00),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertFalse($result->passed());
        self::assertStringContainsString('Money values differ', $result->reason());
        self::assertSame(15.0, $result->context()['proposed_gross']);
        self::assertSame(10.0, $result->context()['current_gross']);
    }

    #[Test]
    public function it_fails_when_tax_types_differ(): void
    {
        $result = (new MoneyEqualsValidator(
            proposed: Money::inclusive(10.00),
            current: Money::exclusive(10.00),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertStringContainsString('inclusive', $result->context()['proposed_tax_type']);
        self::assertStringContainsString('exclusive', $result->context()['current_tax_type']);
    }

    #[Test]
    public function it_passes_when_both_are_zero(): void
    {
        $result = (new MoneyEqualsValidator(
            proposed: Money::inclusive(0.00),
            current: Money::inclusive(0.00),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function or_fail_throws_on_failure(): void
    {
        $result = (new MoneyEqualsValidator(
            proposed: Money::inclusive(20.00),
            current: Money::inclusive(10.00),
        ))->validate();

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('Money values differ', $e->reason());
            self::assertSame('GBP', $e->context()['currency']);
        }
    }

    #[Test]
    public function or_fail_is_noop_on_success(): void
    {
        $result = (new MoneyEqualsValidator(
            proposed: Money::inclusive(10.00),
            current: Money::inclusive(10.00),
        ))->validate();

        $result->orFail();

        self::assertTrue($result->passed());
    }
}
