<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Customer\ValueObjects;

use App\Domain\Customer\Enums\CustomerUpdatableField;
use App\Domain\Customer\ValueObjects\CustomerFieldUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerFieldUpdate::class)]
final class CustomerFieldUpdateTest extends TestCase
{
    #[Test]
    public function first_name_factory_creates_correct_update(): void
    {
        $update = CustomerFieldUpdate::firstName('John');

        self::assertSame(CustomerUpdatableField::FirstName, $update->field);
        self::assertSame('John', $update->value);
    }
}
