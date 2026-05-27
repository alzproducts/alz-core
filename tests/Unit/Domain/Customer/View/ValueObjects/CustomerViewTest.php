<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Customer\View\ValueObjects;

use App\Domain\Customer\View\ValueObjects\CustomerView;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerView::class)]
final class CustomerViewTest extends TestCase
{
    #[Test]
    public function constructor_stores_all_properties_and_wraps_external_id(): void
    {
        $created = new DateTimeImmutable('2026-03-23T10:00:00+00:00');

        $view = new CustomerView(
            externalId: 42,
            email: 'alice@example.com',
            firstName: 'Alice',
            lastName: 'Smith',
            isTrade: true,
            isActive: true,
            createdAt: $created,
        );

        self::assertSame(42, $view->id->value);
        self::assertSame('alice@example.com', $view->email);
        self::assertSame('Alice', $view->firstName);
        self::assertSame('Smith', $view->lastName);
        self::assertTrue($view->isTrade);
        self::assertTrue($view->isActive);
        self::assertSame($created, $view->createdAt);
    }

    #[Test]
    public function constructor_preserves_false_boolean_flags(): void
    {
        $view = $this->makeView(isTrade: false, isActive: false);

        self::assertFalse($view->isTrade);
        self::assertFalse($view->isActive);
    }

    #[Test]
    public function full_name_concatenates_first_and_last_with_space(): void
    {
        $view = $this->makeView(firstName: 'Alice', lastName: 'Smith');

        self::assertSame('Alice Smith', $view->fullName());
    }

    #[Test]
    public function full_name_trims_when_first_name_empty(): void
    {
        $view = $this->makeView(firstName: '', lastName: 'Smith');

        self::assertSame('Smith', $view->fullName());
    }

    #[Test]
    public function full_name_trims_when_last_name_empty(): void
    {
        $view = $this->makeView(firstName: 'Alice', lastName: '');

        self::assertSame('Alice', $view->fullName());
    }

    #[Test]
    public function full_name_returns_empty_when_both_names_empty(): void
    {
        $view = $this->makeView(firstName: '', lastName: '');

        self::assertSame('', $view->fullName());
    }

    private function makeView(
        int $externalId = 1,
        string $email = 'user@example.com',
        string $firstName = 'Test',
        string $lastName = 'User',
        bool $isTrade = false,
        bool $isActive = true,
    ): CustomerView {
        return new CustomerView(
            externalId: $externalId,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            isTrade: $isTrade,
            isActive: $isActive,
            createdAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
