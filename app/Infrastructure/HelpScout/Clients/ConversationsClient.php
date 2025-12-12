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
    use HelpScoutResponseParser;

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
        ], static fn(mixed $v): bool => $v !== null);

        if ($params->waitingSince !== null) {
            $apiParams['query'] = "(customerWaitingSince.time:[* TO {$params->waitingSince}])";
        }

        return $this->parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT, $apiParams),
            'conversations',
            ConversationResponse::class,
            static fn(ConversationResponse $c): DomainConversation => $c->toDomain(),
        );
    }
}
