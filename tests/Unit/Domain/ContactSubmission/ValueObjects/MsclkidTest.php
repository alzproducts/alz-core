<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\Msclkid;
use App\Domain\Exceptions\Data\InvalidFormatException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Msclkid::class)]
final class MsclkidTest extends TestCase
{
    private const string CANONICAL = 'cdd4afcccb1c9a4cad9544dd7e5006d5-1';

    #[Test]
    public function it_accepts_a_canonical_msclkid_and_exposes_the_value(): void
    {
        $msclkid = Msclkid::from(self::CANONICAL);

        $this->assertSame(self::CANONICAL, $msclkid->value);
    }

    #[Test]
    public function it_accepts_the_zero_suffix(): void
    {
        $msclkid = Msclkid::from('cdd4afcccb1c9a4cad9544dd7e5006d5-0');

        $this->assertSame('cdd4afcccb1c9a4cad9544dd7e5006d5-0', $msclkid->value);
    }

    #[Test]
    public function it_accepts_a_plain_32_char_hex_without_suffix(): void
    {
        $msclkid = Msclkid::from('34bcc36adfad1b868316aa8d5b9c4588');

        $this->assertSame('34bcc36adfad1b868316aa8d5b9c4588', $msclkid->value);
    }

    #[Test]
    public function it_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::from('');
    }

    #[Test]
    public function it_rejects_uppercase_hex(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::from('CDD4AFCCCB1C9A4CAD9544DD7E5006D5-1');
    }

    #[Test]
    public function it_rejects_a_suffix_digit_other_than_zero_or_one(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::from('cdd4afcccb1c9a4cad9544dd7e5006d5-2');
    }

    #[Test]
    public function it_rejects_non_hex_characters_in_the_guid_segment(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::from('zzd4afcccb1c9a4cad9544dd7e5006d5-1');
    }

    #[Test]
    public function it_rejects_a_guid_segment_shorter_than_32_chars(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::from('cdd4afcccb1c9a4cad9544dd7e5006d-1');
    }

    #[Test]
    public function it_rejects_a_guid_segment_longer_than_32_chars(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::from('cdd4afcccb1c9a4cad9544dd7e5006d55-1');
    }

    #[Test]
    public function it_rejects_a_standard_uuid_with_internal_hyphens(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::from('cdd4afcc-cb1c-9a4c-ad95-44dd7e5006d5-1');
    }

    #[Test]
    public function fromNullableForm_returns_null_for_null_input(): void
    {
        $this->assertNull(Msclkid::fromNullableForm(null));
    }

    #[Test]
    public function fromNullableForm_returns_null_for_an_empty_string(): void
    {
        $this->assertNull(Msclkid::fromNullableForm(''));
    }

    #[Test]
    public function fromNullableForm_constructs_for_a_valid_input(): void
    {
        $msclkid = Msclkid::fromNullableForm(self::CANONICAL);

        $this->assertInstanceOf(Msclkid::class, $msclkid);
        $this->assertSame(self::CANONICAL, $msclkid->value);
    }

    #[Test]
    public function fromNullableForm_throws_for_an_invalid_non_empty_input(): void
    {
        $this->expectException(InvalidFormatException::class);

        Msclkid::fromNullableForm('not-a-msclkid');
    }

    #[Test]
    public function it_exposes_field_and_value_on_the_exception(): void
    {
        try {
            Msclkid::from('invalid');
            $this->fail('Expected InvalidFormatException');
        } catch (InvalidFormatException $e) {
            $this->assertSame('msclkid', $e->field);
            $this->assertSame('invalid', $e->value);
        }
    }
}
