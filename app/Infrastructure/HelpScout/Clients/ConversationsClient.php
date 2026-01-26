<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\CustomerService\ValueObjects\Conversation as DomainConversation;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\ConversationResponse;
use Override;

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
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    #[Override]
    public function getConversations(ConversationQueryParams $params): array
    {
        /** @var list<DomainConversation> */
        return HelpScoutResponseParser::parseEmbeddedCollectionToDomain(
            $this->transport->get(self::ENDPOINT, $this->buildApiParams($params)),
            'conversations',
            ConversationResponse::class,
        );
    }

    /**
     * Get conversations for multiple queries in parallel.
     *
     * Uses Http::pool() for parallel execution via the transport layer.
     * Falls back to direct call for single queries (no pool overhead).
     *
     * @param list<ConversationQueryParams> $queries
     *
     * @return list<list<DomainConversation>> Results indexed same as input queries
     *
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ExternalServiceUnavailableException When HelpScout API is unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response structure changes
     */
    #[Override]
    public function getConversationsBatch(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        // Single query doesn't need pool overhead
        if (\count($queries) === 1) {
            return [$this->getConversations($queries[0])];
        }

        // Build keyed request params for pool execution
        /** @var array<string, array<string, mixed>> $requests */
        $requests = [];
        foreach ($queries as $index => $query) {
            $requests[(string) $index] = $this->buildApiParams($query);
        }

        /** @phpstan-ignore argument.type (PHP coerces numeric string keys to int) */
        $responses = $this->transport->poolGet($requests);

        // Parse responses preserving original order
        $results = [];
        foreach ($responses as $index => $response) {
            /** @var list<DomainConversation> $conversations */
            $conversations = HelpScoutResponseParser::parseEmbeddedCollectionToDomain(
                $response,
                'conversations',
                ConversationResponse::class,
            );
            $results[(int) $index] = $conversations;
        }

        \ksort($results);

        return \array_values($results);
    }

    /**
     * Build API query parameters from domain query params.
     *
     * @return array<string, mixed>
     */
    private function buildApiParams(ConversationQueryParams $params): array
    {
        return \array_filter([
            'assigned' => $params->agentId,
            'status' => $params->status,
            'tag' => $params->tag,
            'mailbox' => $params->mailboxId,
            'query' => $params->query,
            'sortField' => $params->sortField?->value,
            'sortOrder' => $params->sortOrder?->value,
        ], static fn(mixed $v): bool => $v !== null);
    }
}
