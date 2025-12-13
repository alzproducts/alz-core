<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Infrastructure\HelpScout\Responses\UserResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UserResponse::class)]
final class UserResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response_with_all_fields(): void
    {
        $apiResponse = [
            'id' => 12345,
            'email' => 'agent@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'photoUrl' => 'https://example.com/photo.jpg',
            'role' => 'admin',
            'timezone' => 'Europe/London',
        ];

        $userResponse = UserResponse::from($apiResponse);

        $this->assertSame(12345, $userResponse->id);
        $this->assertSame('agent@example.com', $userResponse->email);
        $this->assertSame('John', $userResponse->firstName);
        $this->assertSame('Doe', $userResponse->lastName);
        $this->assertSame('https://example.com/photo.jpg', $userResponse->photoUrl);
        $this->assertSame('admin', $userResponse->role);
        $this->assertSame('Europe/London', $userResponse->timezone);
    }

    #[Test]
    public function it_parses_api_response_with_nullable_fields(): void
    {
        $apiResponse = [
            'id' => 67890,
            'email' => null,
            'firstName' => null,
            'lastName' => null,
            'photoUrl' => null,
            'role' => null,
            'timezone' => null,
        ];

        $userResponse = UserResponse::from($apiResponse);

        $this->assertSame(67890, $userResponse->id);
        $this->assertNull($userResponse->email);
        $this->assertNull($userResponse->firstName);
        $this->assertNull($userResponse->lastName);
        $this->assertNull($userResponse->photoUrl);
        $this->assertNull($userResponse->role);
        $this->assertNull($userResponse->timezone);
    }

    /*
    |--------------------------------------------------------------------------
    | Email Matching Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function matches_email_returns_true_for_exact_match(): void
    {
        $userResponse = UserResponse::from([
            'id' => 100,
            'email' => 'user@example.com',
            'firstName' => 'Test',
            'lastName' => 'User',
            'photoUrl' => null,
            'role' => null,
            'timezone' => null,
        ]);

        $this->assertTrue($userResponse->matchesEmail('user@example.com'));
    }

    #[Test]
    public function matches_email_is_case_insensitive(): void
    {
        $userResponse = UserResponse::from([
            'id' => 100,
            'email' => 'User@Example.COM',
            'firstName' => 'Test',
            'lastName' => 'User',
            'photoUrl' => null,
            'role' => null,
            'timezone' => null,
        ]);

        $this->assertTrue($userResponse->matchesEmail('user@example.com'));
        $this->assertTrue($userResponse->matchesEmail('USER@EXAMPLE.COM'));
        $this->assertTrue($userResponse->matchesEmail('User@Example.Com'));
    }

    #[Test]
    public function matches_email_returns_false_for_different_email(): void
    {
        $userResponse = UserResponse::from([
            'id' => 100,
            'email' => 'user@example.com',
            'firstName' => 'Test',
            'lastName' => 'User',
            'photoUrl' => null,
            'role' => null,
            'timezone' => null,
        ]);

        $this->assertFalse($userResponse->matchesEmail('other@example.com'));
        $this->assertFalse($userResponse->matchesEmail('user@different.com'));
    }

    #[Test]
    public function matches_email_returns_false_when_user_email_is_null(): void
    {
        $userResponse = UserResponse::from([
            'id' => 100,
            'email' => null,
            'firstName' => 'Test',
            'lastName' => 'User',
            'photoUrl' => null,
            'role' => null,
            'timezone' => null,
        ]);

        $this->assertFalse($userResponse->matchesEmail('any@example.com'));
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_support_agent(): void
    {
        $apiResponse = [
            'id' => 12345,
            'email' => 'support@example.com',
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'photoUrl' => 'https://example.com/jane.jpg',
            'role' => 'user',
            'timezone' => 'America/New_York',
        ];

        $userResponse = UserResponse::from($apiResponse);
        $domainAgent = $userResponse->toDomain();

        $this->assertInstanceOf(SupportAgent::class, $domainAgent);
        $this->assertSame(12345, $domainAgent->id);
        $this->assertSame('support@example.com', $domainAgent->email);
        $this->assertSame('Jane', $domainAgent->firstName);
        $this->assertSame('Smith', $domainAgent->lastName);
    }

    #[Test]
    public function it_throws_when_converting_null_email_to_domain(): void
    {
        $apiResponse = [
            'id' => 99999,
            'email' => null,
            'firstName' => 'Test',
            'lastName' => 'User',
            'photoUrl' => null,
            'role' => null,
            'timezone' => null,
        ];

        $userResponse = UserResponse::from($apiResponse);

        // Domain requires non-empty email - null is invalid
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent email cannot be empty');

        $userResponse->toDomain();
    }
}
