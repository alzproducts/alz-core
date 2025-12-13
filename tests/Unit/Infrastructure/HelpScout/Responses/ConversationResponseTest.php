<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\Conversation as DomainConversation;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\HelpScout\Responses\ConversationResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationResponse::class)]
final class ConversationResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response_with_all_fields(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->assertSame(12345, $conversationResponse->id);
        $this->assertSame(1001, $conversationResponse->number);
        $this->assertSame('Order Issue', $conversationResponse->subject);
        $this->assertSame('active', $conversationResponse->status);
        $this->assertSame('email', $conversationResponse->type);
        $this->assertSame(100, $conversationResponse->mailboxId);
        $this->assertSame(200, $conversationResponse->folderId);
        $this->assertSame('2024-12-10T10:00:00Z', $conversationResponse->createdAt);
        $this->assertSame('2024-12-12T15:30:00Z', $conversationResponse->updatedAt);
        $this->assertSame('2024-12-11T12:00:00Z', $conversationResponse->userUpdatedAt);
        $this->assertSame('2024-12-13T09:00:00Z', $conversationResponse->closedAt);
        $this->assertSame('Help! My order is missing.', $conversationResponse->preview);
        $this->assertSame(5, $conversationResponse->threads);
    }

    #[Test]
    public function it_parses_nested_primary_customer(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->assertNotNull($conversationResponse->primaryCustomer);
        $this->assertSame(500, $conversationResponse->primaryCustomer->id);
        $this->assertSame('John', $conversationResponse->primaryCustomer->first);
        $this->assertSame('Doe', $conversationResponse->primaryCustomer->last);
        $this->assertSame('john@example.com', $conversationResponse->primaryCustomer->email);
    }

    #[Test]
    public function it_parses_nested_assignee(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->assertNotNull($conversationResponse->assignee);
        $this->assertSame(300, $conversationResponse->assignee->id);
        $this->assertSame('Jane', $conversationResponse->assignee->firstName);
        $this->assertSame('Smith', $conversationResponse->assignee->lastName);
        $this->assertSame('jane@company.com', $conversationResponse->assignee->email);
    }

    #[Test]
    public function it_parses_nested_customer_waiting_since(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->assertNotNull($conversationResponse->customerWaitingSince);
        $this->assertSame('2024-12-11T08:00:00Z', $conversationResponse->customerWaitingSince->time);
        $this->assertSame('2 days ago', $conversationResponse->customerWaitingSince->friendly);
    }

    #[Test]
    public function it_parses_nested_snooze(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->assertNotNull($conversationResponse->snooze);
        $this->assertSame(300, $conversationResponse->snooze->snoozedBy);
        $this->assertSame('2024-12-15T09:00:00Z', $conversationResponse->snooze->snoozedUntil);
        $this->assertTrue($conversationResponse->snooze->unsnoozeOnCustomerReply);
    }

    #[Test]
    public function it_parses_nested_tags_collection(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->assertNotNull($conversationResponse->tags);
        $this->assertCount(2, $conversationResponse->tags);
        $this->assertSame(10, $conversationResponse->tags[0]->id);
        $this->assertSame('urgent', $conversationResponse->tags[0]->tag);
        $this->assertSame(20, $conversationResponse->tags[1]->id);
        $this->assertSame('escalation', $conversationResponse->tags[1]->tag);
    }

    #[Test]
    public function it_parses_api_response_with_nullable_fields(): void
    {
        $apiResponse = $this->minimalConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->assertSame(99999, $conversationResponse->id);
        $this->assertSame('New Inquiry', $conversationResponse->subject);
        $this->assertNull($conversationResponse->mailboxId);
        $this->assertNull($conversationResponse->folderId);
        $this->assertNull($conversationResponse->updatedAt);
        $this->assertNull($conversationResponse->userUpdatedAt);
        $this->assertNull($conversationResponse->closedAt);
        $this->assertNull($conversationResponse->primaryCustomer);
        $this->assertNull($conversationResponse->assignee);
        $this->assertNull($conversationResponse->customerWaitingSince);
        $this->assertNull($conversationResponse->snooze);
        $this->assertNull($conversationResponse->tags);
        $this->assertNull($conversationResponse->preview);
        $this->assertNull($conversationResponse->threads);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_conversation(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertInstanceOf(DomainConversation::class, $domainConversation);
        $this->assertSame(12345, $domainConversation->id);
        $this->assertSame(1001, $domainConversation->number);
        $this->assertSame('Order Issue', $domainConversation->subject);
        $this->assertSame('active', $domainConversation->status);
        $this->assertSame(100, $domainConversation->mailboxId);
    }

    #[Test]
    public function it_converts_dates_to_datetime_immutable(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertSame('2024-12-10 10:00:00', $domainConversation->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame('2024-12-12 15:30:00', $domainConversation->updatedAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2024-12-11 12:00:00', $domainConversation->userUpdatedAt?->format('Y-m-d H:i:s'));
        $this->assertSame('2024-12-11 08:00:00', $domainConversation->customerWaitingSince?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_converts_nested_customer_to_domain(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertNotNull($domainConversation->customer);
        $this->assertSame(500, $domainConversation->customer->id);
        $this->assertSame('John', $domainConversation->customer->firstName);
        $this->assertSame('Doe', $domainConversation->customer->lastName);
    }

    #[Test]
    public function it_converts_nested_assignee_to_domain(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertNotNull($domainConversation->assignee);
        $this->assertSame(300, $domainConversation->assignee->id);
        $this->assertSame('Jane', $domainConversation->assignee->firstName);
        $this->assertSame('Smith', $domainConversation->assignee->lastName);
    }

    #[Test]
    public function it_converts_nested_snooze_to_domain(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertNotNull($domainConversation->snooze);
        $this->assertSame('2024-12-15 09:00:00', $domainConversation->snooze->snoozedUntil->format('Y-m-d H:i:s'));
        $this->assertSame(300, $domainConversation->snooze->snoozedByUserId);
    }

    #[Test]
    public function it_converts_nested_tags_to_domain(): void
    {
        $apiResponse = $this->fullConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertCount(2, $domainConversation->tags);
        $this->assertSame(10, $domainConversation->tags[0]->id);
        $this->assertSame('urgent', $domainConversation->tags[0]->name);
        $this->assertSame(20, $domainConversation->tags[1]->id);
        $this->assertSame('escalation', $domainConversation->tags[1]->name);
    }

    #[Test]
    public function it_defaults_mailbox_id_to_zero_when_null(): void
    {
        $apiResponse = $this->minimalConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertSame(0, $domainConversation->mailboxId);
    }

    #[Test]
    public function it_handles_null_optional_dates_in_domain(): void
    {
        $apiResponse = $this->minimalConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertNull($domainConversation->updatedAt);
        $this->assertNull($domainConversation->userUpdatedAt);
        $this->assertNull($domainConversation->customerWaitingSince);
    }

    #[Test]
    public function it_returns_empty_tags_array_when_tags_null(): void
    {
        $apiResponse = $this->minimalConversationPayload();

        $conversationResponse = ConversationResponse::from($apiResponse);
        $domainConversation = $conversationResponse->toDomain();

        $this->assertSame([], $domainConversation->tags);
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid Date Format Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_invalid_api_response_exception_for_malformed_created_at(): void
    {
        $apiResponse = $this->minimalConversationPayload();
        $apiResponse['createdAt'] = 'not-a-date';

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Invalid date format in conversation 99999');

        $conversationResponse->toDomain();
    }

    #[Test]
    public function it_throws_invalid_api_response_exception_for_malformed_updated_at(): void
    {
        $apiResponse = $this->minimalConversationPayload();
        $apiResponse['updatedAt'] = 'invalid-date';

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Invalid date format in conversation 99999');

        $conversationResponse->toDomain();
    }

    #[Test]
    public function it_throws_invalid_api_response_exception_for_malformed_customer_waiting_since(): void
    {
        $apiResponse = $this->minimalConversationPayload();
        $apiResponse['customerWaitingSince'] = [
            'time' => 'bad-timestamp',
            'friendly' => '2 days ago',
        ];

        $conversationResponse = ConversationResponse::from($apiResponse);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Invalid date format in conversation 99999');

        $conversationResponse->toDomain();
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function fullConversationPayload(): array
    {
        return [
            'id' => 12345,
            'number' => 1001,
            'subject' => 'Order Issue',
            'status' => 'active',
            'type' => 'email',
            'mailboxId' => 100,
            'folderId' => 200,
            'createdAt' => '2024-12-10T10:00:00Z',
            'updatedAt' => '2024-12-12T15:30:00Z',
            'userUpdatedAt' => '2024-12-11T12:00:00Z',
            'closedAt' => '2024-12-13T09:00:00Z',
            'primaryCustomer' => [
                'id' => 500,
                'type' => 'customer',
                'first' => 'John',
                'last' => 'Doe',
                'email' => 'john@example.com',
            ],
            'assignee' => [
                'id' => 300,
                'firstName' => 'Jane',
                'lastName' => 'Smith',
                'email' => 'jane@company.com',
                'photoUrl' => 'https://example.com/jane.jpg',
            ],
            'customerWaitingSince' => [
                'time' => '2024-12-11T08:00:00Z',
                'friendly' => '2 days ago',
            ],
            'snooze' => [
                'snoozedBy' => 300,
                'snoozedUntil' => '2024-12-15T09:00:00Z',
                'unsnoozeOnCustomerReply' => true,
            ],
            'tags' => [
                ['id' => 10, 'tag' => 'urgent', 'color' => '#FF0000'],
                ['id' => 20, 'tag' => 'escalation', 'color' => '#FFFF00'],
            ],
            'preview' => 'Help! My order is missing.',
            'threads' => 5,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalConversationPayload(): array
    {
        return [
            'id' => 99999,
            'number' => 2001,
            'subject' => 'New Inquiry',
            'status' => 'pending',
            'type' => 'email',
            'mailboxId' => null,
            'folderId' => null,
            'createdAt' => '2024-12-14T00:00:00Z',
            'updatedAt' => null,
            'userUpdatedAt' => null,
            'closedAt' => null,
            'primaryCustomer' => null,
            'assignee' => null,
            'customerWaitingSince' => null,
            'snooze' => null,
            'tags' => null,
            'preview' => null,
            'threads' => null,
        ];
    }
}
