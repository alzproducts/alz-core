<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Phone;

use App\Infrastructure\Phone\PhoneNormalisationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PhoneNormalisationService::class)]
final class PhoneNormalisationServiceTest extends TestCase
{
    private PhoneNormalisationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PhoneNormalisationService();
    }

    #[Test]
    public function it_returns_null_for_null_input(): void
    {
        $this->assertNull($this->service->toE164(null));
    }

    #[Test]
    public function it_returns_null_for_empty_string(): void
    {
        $this->assertNull($this->service->toE164(''));
    }

    #[Test]
    public function it_returns_null_for_whitespace_only(): void
    {
        $this->assertNull($this->service->toE164('   '));
    }

    #[Test]
    public function it_normalises_uk_national_format_to_e164(): void
    {
        $this->assertSame('+447911123456', $this->service->toE164('07911 123456'));
    }

    #[Test]
    public function it_normalises_uk_number_with_dashes(): void
    {
        $this->assertSame('+447911123456', $this->service->toE164('07911-123-456'));
    }

    #[Test]
    public function it_normalises_international_format_to_e164(): void
    {
        $this->assertSame('+447911123456', $this->service->toE164('+44 7911 123456'));
    }

    #[Test]
    public function it_normalises_us_number_with_explicit_country_code(): void
    {
        $this->assertSame('+12025550123', $this->service->toE164('+1 202 555 0123'));
    }

    #[Test]
    public function it_returns_null_for_unparseable_string(): void
    {
        $this->assertNull($this->service->toE164('not-a-phone'));
    }

    #[Test]
    public function it_returns_null_for_too_short_number(): void
    {
        $this->assertNull($this->service->toE164('12345'));
    }

    #[Test]
    public function it_trims_whitespace_before_parsing(): void
    {
        $this->assertSame('+447911123456', $this->service->toE164('  07911 123456  '));
    }

    #[Test]
    public function it_uses_provided_default_region(): void
    {
        $this->assertSame('+12025550123', $this->service->toE164('(202) 555-0123', 'US'));
    }
}
