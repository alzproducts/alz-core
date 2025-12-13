<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use App\Infrastructure\HelpScout\Responses\AssigneeResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(AssigneeResponse::class)]
final class AssigneeResponseTest extends TestCase
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
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'photoUrl' => 'https://example.com/photo.jpg',
        ];

        $assigneeResponse = AssigneeResponse::from($apiResponse);

        $this->assertSame(12345, $assigneeResponse->id);
        $this->assertSame('John', $assigneeResponse->firstName);
        $this->assertSame('Doe', $assigneeResponse->lastName);
        $this->assertSame('john@example.com', $assigneeResponse->email);
        $this->assertSame('https://example.com/photo.jpg', $assigneeResponse->photoUrl);
    }

    #[Test]
    public function it_parses_api_response_with_nullable_fields(): void
    {
        $apiResponse = [
            'id' => 67890,
            'firstName' => null,
            'lastName' => null,
            'email' => null,
            'photoUrl' => null,
        ];

        $assigneeResponse = AssigneeResponse::from($apiResponse);

        $this->assertSame(67890, $assigneeResponse->id);
        $this->assertNull($assigneeResponse->firstName);
        $this->assertNull($assigneeResponse->lastName);
        $this->assertNull($assigneeResponse->email);
        $this->assertNull($assigneeResponse->photoUrl);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_conversation_assignee(): void
    {
        $apiResponse = [
            'id' => 12345,
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane@example.com',
            'photoUrl' => 'https://example.com/jane.jpg',
        ];

        $assigneeResponse = AssigneeResponse::from($apiResponse);
        $domainAssignee = $assigneeResponse->toDomain();

        $this->assertInstanceOf(ConversationAssignee::class, $domainAssignee);
        $this->assertSame(12345, $domainAssignee->id);
        $this->assertSame('Jane', $domainAssignee->firstName);
        $this->assertSame('Smith', $domainAssignee->lastName);
        $this->assertSame('https://example.com/jane.jpg', $domainAssignee->photoUrl);
    }

    #[Test]
    public function it_converts_null_names_to_empty_strings_in_domain(): void
    {
        $apiResponse = [
            'id' => 99999,
            'firstName' => null,
            'lastName' => null,
            'email' => 'unknown@example.com',
            'photoUrl' => null,
        ];

        $assigneeResponse = AssigneeResponse::from($apiResponse);
        $domainAssignee = $assigneeResponse->toDomain();

        // Domain expects strings, not nulls for firstName/lastName
        $this->assertSame('', $domainAssignee->firstName);
        $this->assertSame('', $domainAssignee->lastName);
    }
}
