<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\Customer\Enums\CustomerType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ContactFormData Value Object Unit Tests.
 *
 * Tests the core form data validation. Assertions ensure invalid data
 * is caught early (fail-fast) rather than propagating through the system.
 */
#[CoversClass(ContactFormData::class)]
final class ContactFormDataTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_required_fields(): void
    {
        $form = new ContactFormData(
            name: 'John Doe',
            email: 'john@example.com',
            reason: ContactReason::ProductInformation,
            message: 'I have a question about your product.',
        );

        self::assertSame('John Doe', $form->name);
        self::assertSame('john@example.com', $form->email);
        self::assertSame(ContactReason::ProductInformation, $form->reason);
        self::assertSame('I have a question about your product.', $form->message);
        self::assertNull($form->phone);
        self::assertNull($form->customerType);
        self::assertNull($form->orderNumber);
        self::assertNull($form->deliveryPostcode);
    }

    #[Test]
    public function it_creates_with_all_fields(): void
    {
        $form = new ContactFormData(
            name: 'Jane Smith',
            email: 'jane@company.co.uk',
            reason: ContactReason::MyOrderDelivery,
            message: 'Where is my order?',
            phone: '+44 7911 123456',
            customerType: CustomerType::Nhs,
            orderNumber: 'ORD-12345',
            deliveryPostcode: 'SW1A 1AA',
        );

        self::assertSame('Jane Smith', $form->name);
        self::assertSame('jane@company.co.uk', $form->email);
        self::assertSame(ContactReason::MyOrderDelivery, $form->reason);
        self::assertSame('Where is my order?', $form->message);
        self::assertSame('+44 7911 123456', $form->phone);
        self::assertSame(CustomerType::Nhs, $form->customerType);
        self::assertSame('ORD-12345', $form->orderNumber);
        self::assertSame('SW1A 1AA', $form->deliveryPostcode);
    }

    /*
    |--------------------------------------------------------------------------
    | Name Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_for_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Name is required');

        new ContactFormData(
            name: '',
            email: 'test@example.com',
            reason: ContactReason::Other,
            message: 'Test message',
        );
    }

    #[Test]
    public function it_accepts_whitespace_name(): void
    {
        // Note: Assert::notEmpty() only checks for empty string, not whitespace
        // Frontend validation handles whitespace; domain accepts for resilience
        $form = new ContactFormData(
            name: '   ',
            email: 'test@example.com',
            reason: ContactReason::Other,
            message: 'Test message',
        );

        self::assertSame('   ', $form->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Email Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_for_empty_email(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email is required');

        new ContactFormData(
            name: 'Test User',
            email: '',
            reason: ContactReason::Other,
            message: 'Test message',
        );
    }

    #[Test]
    public function it_throws_for_email_without_at_symbol(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must contain @');

        new ContactFormData(
            name: 'Test User',
            email: 'invalid-email',
            reason: ContactReason::Other,
            message: 'Test message',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Message Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_for_empty_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message is required');

        new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::Other,
            message: '',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Phone Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_phone_at_max_length(): void
    {
        $maxLengthPhone = \str_repeat('1', 50);

        $form = new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::Other,
            message: 'Test',
            phone: $maxLengthPhone,
        );

        self::assertSame($maxLengthPhone, $form->phone);
    }

    #[Test]
    public function it_throws_for_phone_exceeding_max_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone number too long');

        new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::Other,
            message: 'Test',
            phone: \str_repeat('1', 51),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Order Number Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_order_number_at_max_length(): void
    {
        $maxLengthOrderNumber = \str_repeat('X', 20);

        $form = new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::MyOrderDelivery,
            message: 'Test',
            orderNumber: $maxLengthOrderNumber,
        );

        self::assertSame($maxLengthOrderNumber, $form->orderNumber);
    }

    #[Test]
    public function it_throws_for_order_number_exceeding_max_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order number too long');

        new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::MyOrderDelivery,
            message: 'Test',
            orderNumber: \str_repeat('X', 21),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delivery Postcode Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_delivery_postcode_at_max_length(): void
    {
        $maxLengthPostcode = \str_repeat('A', 20);

        $form = new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::MyOrderDelivery,
            message: 'Test',
            deliveryPostcode: $maxLengthPostcode,
        );

        self::assertSame($maxLengthPostcode, $form->deliveryPostcode);
    }

    #[Test]
    public function it_throws_for_delivery_postcode_exceeding_max_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delivery postcode too long');

        new ContactFormData(
            name: 'Test User',
            email: 'test@example.com',
            reason: ContactReason::MyOrderDelivery,
            message: 'Test',
            deliveryPostcode: \str_repeat('A', 21),
        );
    }
}
