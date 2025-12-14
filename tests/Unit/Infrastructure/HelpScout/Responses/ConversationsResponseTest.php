<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Infrastructure\HelpScout\Responses\ConversationResponse;
use App\Infrastructure\HelpScout\Responses\ConversationsResponse;
use App\Infrastructure\HelpScout\Responses\PageResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationsResponse::class)]
final class ConversationsResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response_with_conversations(): void
    {
        $apiResponse = [
            'conversations' => [
                $this->conversationPayload(1, 'First Conversation'),
                $this->conversationPayload(2, 'Second Conversation'),
            ],
            'page' => [
                'size' => 25,
                'totalElements' => 2,
                'totalPages' => 1,
                'number' => 1,
            ],
        ];

        $conversationsResponse = ConversationsResponse::from($apiResponse);

        $this->assertCount(2, $conversationsResponse->conversations);
        $this->assertInstanceOf(ConversationResponse::class, $conversationsResponse->conversations[0]);
        $this->assertInstanceOf(ConversationResponse::class, $conversationsResponse->conversations[1]);
        $this->assertSame(1, $conversationsResponse->conversations[0]->id);
        $this->assertSame(2, $conversationsResponse->conversations[1]->id);
    }

    #[Test]
    public function it_parses_page_response(): void
    {
        $apiResponse = [
            'conversations' => [
                $this->conversationPayload(1, 'Test Conversation'),
            ],
            'page' => [
                'size' => 25,
                'totalElements' => 100,
                'totalPages' => 4,
                'number' => 2,
            ],
        ];

        $conversationsResponse = ConversationsResponse::from($apiResponse);

        $this->assertInstanceOf(PageResponse::class, $conversationsResponse->page);
        $this->assertSame(25, $conversationsResponse->page->size);
        $this->assertSame(100, $conversationsResponse->page->totalElements);
        $this->assertSame(4, $conversationsResponse->page->totalPages);
        $this->assertSame(2, $conversationsResponse->page->number);
    }

    #[Test]
    public function it_parses_empty_conversations_array(): void
    {
        $apiResponse = [
            'conversations' => [],
            'page' => [
                'size' => 25,
                'totalElements' => 0,
                'totalPages' => 0,
                'number' => 0,
            ],
        ];

        $conversationsResponse = ConversationsResponse::from($apiResponse);

        $this->assertSame([], $conversationsResponse->conversations);
        $this->assertSame(0, $conversationsResponse->page->totalElements);
    }

    #[Test]
    public function it_parses_full_page_of_conversations(): void
    {
        $conversations = [];
        for ($i = 1; $i <= 25; $i++) {
            $conversations[] = $this->conversationPayload($i, "Conversation {$i}");
        }

        $apiResponse = [
            'conversations' => $conversations,
            'page' => [
                'size' => 25,
                'totalElements' => 75,
                'totalPages' => 3,
                'number' => 1,
            ],
        ];

        $conversationsResponse = ConversationsResponse::from($apiResponse);

        $this->assertCount(25, $conversationsResponse->conversations);
        $this->assertTrue($conversationsResponse->page->hasMorePages());
    }

    /*
    |--------------------------------------------------------------------------
    | Nested Conversation Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_conversations_with_nested_structures(): void
    {
        $apiResponse = [
            'conversations' => [
                [
                    'id' => 12345,
                    'number' => 1001,
                    'subject' => 'Complex Conversation',
                    'status' => 'active',
                    'type' => 'email',
                    'mailboxId' => 100,
                    'folderId' => null,
                    'createdAt' => '2024-12-14T10:00:00Z',
                    'updatedAt' => '2024-12-14T12:00:00Z',
                    'userUpdatedAt' => null,
                    'closedAt' => null,
                    'primaryCustomer' => [
                        'id' => 500,
                        'type' => 'customer',
                        'first' => 'Alice',
                        'last' => 'Wonder',
                        'email' => 'alice@example.com',
                    ],
                    'assignee' => [
                        'id' => 300,
                        'firstName' => 'Bob',
                        'lastName' => 'Agent',
                        'email' => 'bob@company.com',
                        'photoUrl' => null,
                    ],
                    'customerWaitingSince' => [
                        'time' => '2024-12-14T11:00:00Z',
                        'friendly' => '1 hour ago',
                    ],
                    'snooze' => null,
                    'tags' => [
                        ['id' => 10, 'tag' => 'vip', 'color' => '#0000FF'],
                    ],
                    'preview' => 'Need help with my order...',
                    'threads' => 3,
                ],
            ],
            'page' => [
                'size' => 25,
                'totalElements' => 1,
                'totalPages' => 1,
                'number' => 1,
            ],
        ];

        $conversationsResponse = ConversationsResponse::from($apiResponse);
        $conversation = $conversationsResponse->conversations[0];

        // Verify nested structures are parsed
        $this->assertNotNull($conversation->primaryCustomer);
        $this->assertSame('Alice', $conversation->primaryCustomer->first);

        $this->assertNotNull($conversation->assignee);
        $this->assertSame('Bob', $conversation->assignee->firstName);

        $this->assertNotNull($conversation->customerWaitingSince);
        $this->assertSame('1 hour ago', $conversation->customerWaitingSince->friendly);

        $this->assertNotNull($conversation->tags);
        $this->assertCount(1, $conversation->tags);
        $this->assertSame('vip', $conversation->tags[0]->tag);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(int $id, string $subject): array
    {
        return [
            'id' => $id,
            'number' => 1000 + $id,
            'subject' => $subject,
            'status' => 'active',
            'type' => 'email',
            'mailboxId' => 100,
            'folderId' => null,
            'createdAt' => '2024-12-14T10:00:00Z',
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
