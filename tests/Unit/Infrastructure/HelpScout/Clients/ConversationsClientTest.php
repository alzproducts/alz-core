<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Clients;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Infrastructure\HelpScout\Clients\ConversationsClient;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationsClient::class)]
final class ConversationsClientTest extends TestCase
{
    private HelpScoutHttpTransport&MockInterface $mockTransport;

    private ConversationsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = Mockery::mock(HelpScoutHttpTransport::class);

        $this->client = new ConversationsClient($this->mockTransport);
    }

    /*
    |--------------------------------------------------------------------------
    | getConversations Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_conversations_returns_domain_conversations(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([
            $this->conversationPayload(1, 'First Issue'),
            $this->conversationPayload(2, 'Second Issue'),
        ]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::any())
            ->once()
            ->andReturn($mockResponse);

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $result = $this->client->getConversations($params);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Conversation::class, $result[0]);
        $this->assertInstanceOf(Conversation::class, $result[1]);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame(2, $result[1]->id);
    }

    #[Test]
    public function get_conversations_passes_agent_id_as_assigned_parameter(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::on(static fn(array $params) => ($params['assigned'] ?? null) === 12345))
            ->once()
            ->andReturn($mockResponse);

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $this->client->getConversations($params);
    }

    #[Test]
    public function get_conversations_passes_status_parameter(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::on(static fn(array $params) => ($params['status'] ?? null) === 'active,pending'))
            ->once()
            ->andReturn($mockResponse);

        // assigned() factory sets status to 'active,pending'
        $params = ConversationQueryParams::assigned(agentId: 12345);
        $this->client->getConversations($params);
    }

    #[Test]
    public function get_conversations_passes_tag_parameter(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::on(static fn(array $params) => ($params['tag'] ?? null) === 'server to-do'))
            ->once()
            ->andReturn($mockResponse);

        // todos() factory sets tag to 'server to-do'
        $params = ConversationQueryParams::todos(agentId: 12345);
        $this->client->getConversations($params);
    }

    #[Test]
    public function get_conversations_passes_mailbox_id_parameter(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::on(static fn(array $params) => ($params['mailbox'] ?? null) === 99999))
            ->once()
            ->andReturn($mockResponse);

        // latePriority() factory accepts mailboxId
        $params = ConversationQueryParams::latePriority(
            mailboxId: 99999,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );
        $this->client->getConversations($params);
    }

    #[Test]
    public function get_conversations_passes_sort_parameters(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::on(static fn(array $params) => ($params['sortField'] ?? null) === 'waitingSince'
                    && ($params['sortOrder'] ?? null) === 'asc'))
            ->once()
            ->andReturn($mockResponse);

        // latePriority() factory sets sortField and sortOrder
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['priority'],
            excludedTags: [],
            thresholdHours: 12,
        );
        $this->client->getConversations($params);
    }

    #[Test]
    public function get_conversations_passes_query_parameter(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::on(static function (array $params) {
                // latePriority builds a query string with waiting time filter
                return isset($params['query']) && \str_contains($params['query'], 'waitingSince');
            }))
            ->once()
            ->andReturn($mockResponse);

        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );
        $this->client->getConversations($params);
    }

    #[Test]
    public function get_conversations_for_negative_reviews(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::on(static fn(array $params) => ($params['tag'] ?? null) === 'feedback-review-negative'
                    && ($params['status'] ?? null) === 'active'))
            ->once()
            ->andReturn($mockResponse);

        $params = ConversationQueryParams::negativeReviews();
        $this->client->getConversations($params);
    }

    /*
    |--------------------------------------------------------------------------
    | getConversationsBatch Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_conversations_batch_returns_empty_array_for_empty_queries(): void
    {
        $result = $this->client->getConversationsBatch([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function get_conversations_batch_uses_direct_call_for_single_query(): void
    {
        $mockResponse = $this->createMockResponse($this->conversationsApiResponse([
            $this->conversationPayload(1, 'Single Result'),
        ]));

        $this->mockTransport->expects('get')
            ->with('/conversations', Mockery::any())
            ->once()
            ->andReturn($mockResponse);

        // poolGet should NOT be called for single query - uses direct get() instead
        $this->mockTransport->shouldNotReceive('poolGet');

        $queries = [ConversationQueryParams::assigned(agentId: 12345)];
        $result = $this->client->getConversationsBatch($queries);

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]);
        $this->assertSame(1, $result[0][0]->id);
    }

    #[Test]
    public function get_conversations_batch_uses_pool_for_multiple_queries(): void
    {
        // Build mock responses for each query
        $mockResponse1 = $this->createMockResponse($this->conversationsApiResponse([
            $this->conversationPayload(1, 'Query 1 Result'),
        ]));
        $mockResponse2 = $this->createMockResponse($this->conversationsApiResponse([
            $this->conversationPayload(2, 'Query 2 Result'),
        ]));

        // poolGet receives keyed request params and returns keyed responses
        $this->mockTransport->expects('poolGet')
            ->with(Mockery::on(static fn(array $requests) => \count($requests) === 2
                    && isset($requests['0'], $requests['1'])))
            ->once()
            ->andReturn(['0' => $mockResponse1, '1' => $mockResponse2]);

        $queries = [
            ConversationQueryParams::assigned(agentId: 111),
            ConversationQueryParams::assigned(agentId: 222),
        ];

        $result = $this->client->getConversationsBatch($queries);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0][0]->id);
        $this->assertSame(2, $result[1][0]->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $json
     */
    private function createMockResponse(array $json): Response&MockInterface
    {
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->allows('json')->andReturn($json);

        return $mockResponse;
    }

    /**
     * @param list<array<string, mixed>> $conversations
     *
     * @return array<string, mixed>
     */
    private function conversationsApiResponse(array $conversations): array
    {
        return [
            '_embedded' => [
                'conversations' => $conversations,
            ],
            'page' => [
                'size' => 25,
                'totalElements' => \count($conversations),
                'totalPages' => 1,
                'number' => 1,
            ],
        ];
    }

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
