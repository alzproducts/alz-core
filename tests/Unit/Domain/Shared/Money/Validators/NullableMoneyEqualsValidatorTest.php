<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Money\Validators;

use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Money\Validators\NullableMoneyEqualsResult;
use App\Domain\Shared\Money\Validators\NullableMoneyEqualsValidator;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullableMoneyEqualsValidator::class)]
#[CoversClass(NullableMoneyEqualsResult::class)]
final class NullableMoneyEqualsValidatorTest extends TestCase
{
    #[Test]
    public function it_passes_when_both_are_null(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: null,
            current: null,
        ))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame('', $result->reason());
        self::assertSame([], $result->context());
    }

    #[Test]
    public function it_passes_when_both_are_identical_money(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: Money::inclusive(15.00),
            current: Money::inclusive(15.00),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_fails_when_proposed_is_null_and_current_is_not(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: null,
            current: Money::inclusive(10.00),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertNull($result->context()['proposed_gross']);
        self::assertSame(10.0, $result->context()['current_gross']);
        self::assertStringContainsString('null', $result->reason());
    }

    #[Test]
    public function it_fails_when_current_is_null_and_proposed_is_not(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: Money::inclusive(10.00),
            current: null,
        ))->validate();

        self::assertTrue($result->failed());
        self::assertSame(10.0, $result->context()['proposed_gross']);
        self::assertNull($result->context()['current_gross']);
    }

    #[Test]
    public function it_fails_when_both_non_null_but_amounts_differ(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: Money::inclusive(20.00),
            current: Money::inclusive(10.00),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertSame(20.0, $result->context()['proposed_gross']);
        self::assertSame(10.0, $result->context()['current_gross']);
    }

    #[Test]
    public function it_fails_when_both_non_null_but_tax_types_differ(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: Money::inclusive(10.00),
            current: Money::exclusive(10.00),
        ))->validate();

        self::assertTrue($result->failed());
    }

    #[Test]
    public function or_fail_throws_on_failure(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: null,
            current: Money::inclusive(10.00),
        ))->validate();

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('Nullable Money values differ', $e->reason());
            self::assertNull($e->context()['proposed_gross']);
            self::assertSame(10.0, $e->context()['current_gross']);
        }
    }

    #[Test]
    public function or_fail_is_noop_on_success(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: null,
            current: null,
        ))->validate();

        $result->orFail();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function reason_formats_both_null_values(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: null,
            current: Money::inclusive(25.50),
        ))->validate();

        self::assertSame(
            'Nullable Money values differ: proposed null vs current £25.50',
            $result->reason(),
        );
    }

    #[Test]
    public function reason_formats_both_money_values(): void
    {
        $result = (new NullableMoneyEqualsValidator(
            proposed: Money::inclusive(30.00),
            current: Money::inclusive(20.00),
        ))->validate();

        self::assertSame(
            'Nullable Money values differ: proposed £30.00 vs current £20.00',
            $result->reason(),
        );
    }
}
