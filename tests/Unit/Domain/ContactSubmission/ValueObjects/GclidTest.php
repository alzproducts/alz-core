<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\Gclid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gclid::class)]
final class GclidTest extends TestCase
{
    #[Test]
    public function it_accepts_a_real_production_gclid_and_exposes_the_value(): void
    {
        $gclid = Gclid::from('CNHz5eD_8pkCFRCdnAodzniYQg');

        $this->assertSame('CNHz5eD_8pkCFRCdnAodzniYQg', $gclid->value);
    }

    #[Test]
    public function it_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gclid::from('');
    }

    #[Test]
    public function it_rejects_a_string_below_the_minimum_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gclid::from(\str_repeat('a', 9));
    }

    #[Test]
    public function it_accepts_the_minimum_length(): void
    {
        $gclid = Gclid::from(\str_repeat('a', 10));

        $this->assertSame(\str_repeat('a', 10), $gclid->value);
    }

    #[Test]
    public function it_accepts_the_maximum_length(): void
    {
        $gclid = Gclid::from(\str_repeat('a', 250));

        $this->assertSame(\str_repeat('a', 250), $gclid->value);
    }

    #[Test]
    public function it_rejects_a_string_above_the_maximum_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gclid::from(\str_repeat('a', 251));
    }

    #[Test]
    public function it_rejects_a_string_containing_a_space(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gclid::from('CNHz5eD 8pkCFRCdnAodzniYQg');
    }

    #[Test]
    public function it_rejects_a_string_containing_a_dot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // `.` is the fbclid separator — must not pass as a gclid.
        Gclid::from('IwAR0abcdef.ghijklmnop');
    }

    #[Test]
    public function it_rejects_non_url_safe_base64_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gclid::from('CNHz5eD+8pkCFRCdnAodzniYQg');
    }

    #[Test]
    public function fromNullableForm_returns_null_for_null_input(): void
    {
        $this->assertNull(Gclid::fromNullableForm(null));
    }

    #[Test]
    public function fromNullableForm_returns_null_for_an_empty_string(): void
    {
        $this->assertNull(Gclid::fromNullableForm(''));
    }

    #[Test]
    public function fromNullableForm_constructs_for_a_valid_input(): void
    {
        $gclid = Gclid::fromNullableForm('CNHz5eD_8pkCFRCdnAodzniYQg');

        $this->assertInstanceOf(Gclid::class, $gclid);
        $this->assertSame('CNHz5eD_8pkCFRCdnAodzniYQg', $gclid->value);
    }

    #[Test]
    public function fromNullableForm_throws_for_an_invalid_non_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gclid::fromNullableForm('garbage with spaces');
    }
}
