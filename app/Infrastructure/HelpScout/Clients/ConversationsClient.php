<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\CustomerService\ValueObjects\Conversation as DomainConversation;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\ConversationResponse;
use Closure;
use Illuminate\Contracts\Concurrency\Driver as ConcurrencyDriver;
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
        private ConcurrencyDriver $concurrency,
    ) {}

    /**
     * Get conversations based on query parameters.
     *
     * @return list<DomainConversation>
     */
    #[Override]
    public function getConversations(ConversationQueryParams $params): array
    {
        $apiParams = \array_filter([
            'assigned' => $params->agentId,
            'status' => $params->status,
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

    /**
     * Get conversations for multiple queries in parallel.
     *
     * Uses Laravel's Concurrency driver for parallel execution.
     * Falls back to sequential execution for single queries.
     *
     * @param list<ConversationQueryParams> $queries
     *
     * @return list<list<DomainConversation>> Results indexed same as input queries
     *
     * @throws ExternalServiceUnavailableException When HelpScout API is unavailable
     * @throws InvalidApiResponseException When API response structure changes
     */
    #[Override]
    public function getConversationsBatch(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        // Single query doesn't need concurrency overhead
        if (\count($queries) === 1) {
            return [$this->getConversations($queries[0])];
        }

        $closures = [];

        foreach ($queries as $query) {
            $closures[] = $this->createQueryClosure($query);
        }

        /** @var list<list<DomainConversation>> $results */
        $results = $this->concurrency->run($closures);

        return $results;
    }

    /**
     * Create a closure for parallel query execution.
     *
     * @return Closure(): list<DomainConversation>
     */
    private function createQueryClosure(ConversationQueryParams $query): Closure
    {
        // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Closures passed to ConcurrencyDriver::run() - exceptions propagate via IPC)
        return fn(): array => $this->getConversations($query);
    }
}
