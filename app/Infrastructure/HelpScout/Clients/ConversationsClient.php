<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\CustomerService\ValueObjects\Conversation as DomainConversation;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\ConversationResponse;

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
    private const string ENDPOINT = '/conversations';

    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    /**
     * Get conversations based on query parameters.
     *
     * @return list<DomainConversation>
     */
    public function getConversations(ConversationQueryParams $params): array
    {
        $apiParams = \array_filter([
            'assigned' => $params->agentId,
            'status' => $params->status ?? 'active',
            'tag' => $params->tag,
            'mailbox' => $params->mailboxId,
            'query' => $params->query,
            'sortField' => $params->sortField?->value,
            'sortOrder' => $params->sortOrder?->value,
        ], static fn(mixed $v): bool => $v !== null);

        /** @var list<DomainConversation> */
        return HelpScoutResponseParser::parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT, $apiParams),
            'conversations',
            ConversationResponse::class,
        );
    }
}
