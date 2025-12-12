<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Domain\CustomerService\ValueObjects\Conversation as DomainConversation;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\Conversation;

/**
 * HelpScout Conversations API Client.
 *
 * Handles conversation queries with various filters:
 * - By assignee (agent's inbox)
 * - By tag (to-do, negative reviews, etc.)
 * - By waiting time (escalations)
 *
 * Transforms Infrastructure DTOs to Domain value objects at the boundary.
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/conversations/
 */
final readonly class ConversationsClient implements ConversationsClientInterface
{
    use HelpScoutResponseParser;

    private const string ENDPOINT = '/conversations';

    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    /**
     * Get conversations assigned to a specific agent.
     *
     * @return list<DomainConversation>
     */
    public function getAssignedTo(int $agentId, string $status = 'active'): array
    {
        return $this->searchToDomain([
            'assigned' => $agentId,
            'status' => $status,
        ]);
    }

    /**
     * Get conversations with a specific tag for an agent.
     *
     * @return list<DomainConversation>
     */
    public function getWithTagForAgent(int $agentId, string $tag): array
    {
        return $this->searchToDomain([
            'assigned' => $agentId,
            'tag' => $tag,
            'status' => 'active',
        ]);
    }

    /**
     * Get conversations with a specific tag (unfiltered by agent).
     *
     * @return list<DomainConversation>
     */
    public function getWithTag(string $tag, string $status = 'active'): array
    {
        return $this->searchToDomain(\compact('tag', 'status'));
    }

    /**
     * Get conversations in a mailbox waiting since a specific time.
     *
     * Used for escalation queries (late responses).
     *
     * @return list<DomainConversation>
     */
    public function getWaitingSince(int $mailboxId, string $waitingSinceQuery): array
    {
        return $this->searchToDomain([
            'mailbox' => $mailboxId,
            'status' => 'active',
            'query' => "(customerWaitingSince.time:[* TO {$waitingSinceQuery}])",
        ]);
    }

    /**
     * Search conversations and transform to Domain value objects.
     *
     * @param array<string, mixed> $params Query parameters for the HelpScout conversations API
     *
     * @return list<DomainConversation>
     *
     * @see https://developer.helpscout.com/mailbox-api/endpoints/conversations/list/
     */
    private function searchToDomain(array $params): array
    {
        return $this->parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT, $params),
            'conversations',
            Conversation::class,
            static fn(Conversation $c): DomainConversation => $c->toDomain(),
        );
    }
}
