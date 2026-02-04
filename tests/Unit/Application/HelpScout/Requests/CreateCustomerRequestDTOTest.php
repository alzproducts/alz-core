<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Requests;

use App\Application\HelpScout\Requests\CreateCustomerRequestDTO;
use App\Domain\Exceptions\Data\InsufficientDataException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CreateCustomerRequestDTO Unit Tests.
 *
 * Tests validation (requires email OR phone) and normalization (lowercase, trim).
 * Critical for HelpScout customer creation - missing contact methods prevent customer lookup.
 */
#[CoversClass(CreateCustomerRequestDTO::class)]
final class CreateCustomerRequestDTOTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Validation Tests - CRITICAL BUSINESS LOGIC
    |--------------------------------------------------------------------------
    | At least one contact method (email or phone) must be provided.
    | This is a HelpScout API requirement - customers need a way to be contacted.
    */

    #[Test]
    public function it_accepts_email_only(): void
    {
        $dto = new CreateCustomerRequestDTO(email: 'customer@example.com');

        self::assertSame('customer@example.com', $dto->email);
        self::assertNull($dto->phone);
    }

    #[Test]
    public function it_accepts_phone_only(): void
    {
        $dto = new CreateCustomerRequestDTO(phone: '+44 7911 123456');

        self::assertNull($dto->email);
        self::assertSame('+44 7911 123456', $dto->phone);
    }

    #[Test]
    public function it_accepts_both_email_and_phone(): void
    {
        $dto = new CreateCustomerRequestDTO(
            email: 'customer@example.com',
            phone: '+44 7911 123456',
        );

        self::assertSame('customer@example.com', $dto->email);
        self::assertSame('+44 7911 123456', $dto->phone);
    }

    #[Test]
    #[DataProvider('invalidContactMethodsProvider')]
    public function it_throws_when_no_contact_method_provided(?string $email, ?string $phone): void
    {
        $this->expectException(InsufficientDataException::class);

        new CreateCustomerRequestDTO(email: $email, phone: $phone);
    }

    /**
     * @return array<string, array{email: ?string, phone: ?string}>
     */
    public static function invalidContactMethodsProvider(): array
    {
        return [
            'both null' => ['email' => null, 'phone' => null],
            'both empty strings' => ['email' => '', 'phone' => ''],
            'email null, phone empty' => ['email' => null, 'phone' => ''],
            'email empty, phone null' => ['email' => '', 'phone' => null],
            'email whitespace only' => ['email' => '   ', 'phone' => null],
            'phone whitespace only' => ['email' => null, 'phone' => '   '],
            'both whitespace only' => ['email' => '  ', 'phone' => '  '],
        ];
    }

    #[Test]
    public function exception_message_describes_missing_contact_method(): void
    {
        try {
            new CreateCustomerRequestDTO();
            self::fail('Expected InsufficientDataException');
        } catch (InsufficientDataException $e) {
            self::assertStringContainsString('Customer', $e->getMessage());
            self::assertStringContainsString('email or phone', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Normalization Tests
    |--------------------------------------------------------------------------
    | Email is lowercased and trimmed. Name and phone are trimmed only.
    */

    #[Test]
    public function it_lowercases_email(): void
    {
        $dto = new CreateCustomerRequestDTO(email: 'Customer@Example.COM');

        self::assertSame('customer@example.com', $dto->email);
    }

    #[Test]
    public function it_trims_email(): void
    {
        $dto = new CreateCustomerRequestDTO(email: '  customer@example.com  ');

        self::assertSame('customer@example.com', $dto->email);
    }

    #[Test]
    public function it_trims_name(): void
    {
        $dto = new CreateCustomerRequestDTO(
            email: 'test@example.com',
            name: '  John Smith  ',
        );

        self::assertSame('John Smith', $dto->name);
    }

    #[Test]
    public function it_preserves_name_case(): void
    {
        $dto = new CreateCustomerRequestDTO(
            email: 'test@example.com',
            name: 'John McDonald',
        );

        self::assertSame('John McDonald', $dto->name);
    }

    #[Test]
    public function it_trims_phone(): void
    {
        $dto = new CreateCustomerRequestDTO(
            email: 'test@example.com',
            phone: '  +44 7911 123456  ',
        );

        self::assertSame('+44 7911 123456', $dto->phone);
    }

    #[Test]
    public function it_sets_null_name_when_null_provided(): void
    {
        $dto = new CreateCustomerRequestDTO(email: 'test@example.com', name: null);

        self::assertNull($dto->name);
    }

    #[Test]
    public function it_sets_null_phone_when_null_provided(): void
    {
        $dto = new CreateCustomerRequestDTO(email: 'test@example.com', phone: null);

        self::assertNull($dto->phone);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Case Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_unicode_email(): void
    {
        // Some email providers support unicode in local part
        $dto = new CreateCustomerRequestDTO(email: 'Müller@example.com');

        self::assertSame('müller@example.com', $dto->email);
    }

    #[Test]
    public function it_handles_unicode_name(): void
    {
        $dto = new CreateCustomerRequestDTO(
            email: 'test@example.com',
            name: '  François Müller  ',
        );

        self::assertSame('François Müller', $dto->name);
    }
}
