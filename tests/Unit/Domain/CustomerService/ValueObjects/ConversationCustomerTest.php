<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationCustomer::class)]
final class ConversationCustomerTest extends TestCase
{
    #[Test]
    public function it_creates_valid_customer_with_all_fields(): void
    {
        $customer = new ConversationCustomer(
            id: 12345,
            firstName: 'Alice',
            lastName: 'Johnson',
            email: 'alice@example.com',
        );

        $this->assertSame(12345, $customer->id);
        $this->assertSame('Alice', $customer->firstName);
        $this->assertSame('Johnson', $customer->lastName);
        $this->assertSame('alice@example.com', $customer->email);
    }

    #[Test]
    public function it_creates_valid_customer_with_nullable_fields(): void
    {
        $customer = new ConversationCustomer(
            id: 67890,
            firstName: null,
            lastName: null,
            email: null,
        );

        $this->assertSame(67890, $customer->id);
        $this->assertNull($customer->firstName);
        $this->assertNull($customer->lastName);
        $this->assertNull($customer->email);
    }

    #[Test]
    public function it_creates_customer_with_partial_information(): void
    {
        $customer = new ConversationCustomer(
            id: 11111,
            firstName: 'Bob',
            lastName: null,
            email: 'bob@example.com',
        );

        $this->assertSame(11111, $customer->id);
        $this->assertSame('Bob', $customer->firstName);
        $this->assertNull($customer->lastName);
        $this->assertSame('bob@example.com', $customer->email);
    }

    #[Test]
    public function it_accepts_customer_id_of_one(): void
    {
        $customer = new ConversationCustomer(
            id: 1,
            firstName: 'Test',
            lastName: 'Customer',
            email: 'test@example.com',
        );

        $this->assertSame(1, $customer->id);
    }

    #[Test]
    public function it_rejects_zero_customer_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer ID must be positive');

        new ConversationCustomer(
            id: 0,
            firstName: 'Test',
            lastName: 'Customer',
            email: 'test@example.com',
        );
    }

    #[Test]
    public function it_rejects_negative_customer_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Customer ID must be positive');

        new ConversationCustomer(
            id: -1,
            firstName: 'Test',
            lastName: 'Customer',
            email: 'test@example.com',
        );
    }
}
