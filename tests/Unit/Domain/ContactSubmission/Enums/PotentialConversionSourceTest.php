<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\Enums;

use App\Domain\ContactSubmission\Enums\PotentialConversionSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PotentialConversionSource::class)]
final class PotentialConversionSourceTest extends TestCase
{
    #[Test]
    public function form_case_has_form_backing_value(): void
    {
        self::assertSame('form', PotentialConversionSource::Form->value);
    }

    #[Test]
    public function call_case_has_call_backing_value(): void
    {
        self::assertSame('call', PotentialConversionSource::Call->value);
    }

    #[Test]
    public function enum_has_exactly_two_cases(): void
    {
        self::assertCount(2, PotentialConversionSource::cases());
    }
}
