<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Data\InvalidFormatException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhoneNumberE164::class)]
final class PhoneNumberE164Test extends TestCase
{
    #[Test]
    public function it_accepts_a_uk_mobile_number(): void
    {
        $phone = PhoneNumberE164::from('+447712345678');

        $this->assertSame('+447712345678', $phone->value);
    }

    #[Test]
    public function it_accepts_a_us_number(): void
    {
        $phone = PhoneNumberE164::from('+12025551234');

        $this->assertSame('+12025551234', $phone->value);
    }

    #[Test]
    public function it_accepts_an_australian_mobile_number(): void
    {
        $phone = PhoneNumberE164::from('+61412345678');

        $this->assertSame('+61412345678', $phone->value);
    }

    #[Test]
    public function it_accepts_the_minimum_e164_length(): void
    {
        // E.164: '+' + country digit (1-9) + at least 6 subscriber digits = 8 chars.
        $phone = PhoneNumberE164::from('+1234567');

        $this->assertSame('+1234567', $phone->value);
    }

    #[Test]
    public function it_accepts_the_maximum_e164_length(): void
    {
        // E.164: 15 digits total after the '+'.
        $phone = PhoneNumberE164::from('+123456789012345');

        $this->assertSame('+123456789012345', $phone->value);
    }

    #[Test]
    public function it_rejects_a_number_missing_the_plus_prefix(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::from('447712345678');
    }

    #[Test]
    public function it_rejects_a_country_code_starting_with_zero(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::from('+0447712345678');
    }

    #[Test]
    public function it_rejects_letters(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::from('+44ABCDEFGH');
    }

    #[Test]
    public function it_rejects_a_number_below_minimum_length(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::from('+123456');
    }

    #[Test]
    public function it_rejects_a_number_above_maximum_length(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::from('+1234567890123456');
    }

    #[Test]
    public function it_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::from('');
    }

    #[Test]
    public function it_rejects_internal_whitespace(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::from('+44 7712 345678');
    }

    #[Test]
    public function fromNullableForm_returns_null_for_null_input(): void
    {
        $this->assertNull(PhoneNumberE164::fromNullableForm(null));
    }

    #[Test]
    public function fromNullableForm_returns_null_for_an_empty_string(): void
    {
        $this->assertNull(PhoneNumberE164::fromNullableForm(''));
    }

    #[Test]
    public function fromNullableForm_constructs_for_a_valid_input(): void
    {
        $phone = PhoneNumberE164::fromNullableForm('+447712345678');

        $this->assertInstanceOf(PhoneNumberE164::class, $phone);
        $this->assertSame('+447712345678', $phone->value);
    }

    #[Test]
    public function fromNullableForm_throws_for_an_invalid_non_empty_input(): void
    {
        $this->expectException(InvalidFormatException::class);

        PhoneNumberE164::fromNullableForm('not-a-number');
    }

    #[Test]
    public function it_exposes_field_and_value_on_the_exception(): void
    {
        try {
            PhoneNumberE164::from('invalid');
            $this->fail('Expected InvalidFormatException');
        } catch (InvalidFormatException $e) {
            $this->assertSame('phone_number_e164', $e->field);
            $this->assertSame('invalid', $e->value);
        }
    }
}
