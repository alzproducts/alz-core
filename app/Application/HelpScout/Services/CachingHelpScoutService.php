<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Services;

use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Support\MailboxEnrichmentService;
use App\Application\Support\CacheTimesTrait;
use App\Application\Support\GracefulCache;
use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\CustomerService\ValueObjects\Mailbox;
use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Domain\Exceptions\ConfigurationNotFoundException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use LogicException;

/**
 * Caching decorator for HelpScout API operations.
 *
 * Adds caching layer to HelpScout endpoint clients without modifying
 * the underlying implementations. Uses GracefulCache for resilient caching
 * that degrades gracefully on backend failures.
 *
 * Cache Strategy:
 * - Agent profiles: 7 days TTL (user→agent rarely changes)
 * - Mailboxes: 7 days TTL (rarely changes)
 * - Conversations: 5 minutes TTL (frequently updated)
 * - Escalation config: 5 minutes TTL (admin-configurable)
 */
final readonly class CachingHelpScoutService
{
    use CacheTimesTrait;

    public const string CACHE_PREFIX = 'helpscout';

    private const string KEY_AGENT_PROFILE = self::CACHE_PREFIX . ':agent:profile:';

    private const string KEY_MAILBOXES = self::CACHE_PREFIX . ':mailboxes';

    private const string KEY_ESCALATION_CONFIG = self::CACHE_PREFIX . ':escalation-config';

    public function __construct(
        private ConversationsClientInterface $conversationsClient,
        private AgentsClientInterface $agentsClient,
        private MailboxesClientInterface $mailboxesClient,
        private EscalationsConfigRepositoryInterface $escalationsConfigRepository,
        private MailboxEnrichmentService $enricher,
        private GracefulCache $cache,
    ) {}

    /**
     * Get agent profile by email with caching.
     *
     * Returns the full SupportAgent including role information.
     * Cached for 7 days (profile data rarely changes).
     *
     * @throws CustomerServiceAgentNotFoundException When no agent matches email
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getAgentProfile(string $email): SupportAgent
    {
        $normalizedEmail = \mb_strtolower(\mb_trim($email));
        $cacheKey = self::KEY_AGENT_PROFILE . \hash('xxh3', $normalizedEmail);

        $agent = $this->cache->remember(
            $cacheKey,
            self::SEVEN_DAYS,
            fn(): ?SupportAgent => $this->agentsClient->findByEmail($normalizedEmail),
        );

        if ($agent === null) {
            throw new CustomerServiceAgentNotFoundException($normalizedEmail);
        }

        return $agent;
    }

    /**
     * Get conversations with caching and mailbox enrichment.
     *
     * @return list<Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getConversations(ConversationQueryParams $params): array
    {
        return $this->cache->remember(
            $params->getCacheKey(),
            $params->ttlSeconds,
            fn(): array => $this->enricher->enrich(
                $this->conversationsClient->getConversations($params),
            ),
        );
    }

    /**
     * Get conversations for multiple queries with caching and parallel fetching.
     *
     * Checks cache for each query, fetches uncached queries in parallel,
     * then enriches and caches results. Returns a flat list of all conversations.
     *
     * @param list<ConversationQueryParams> $queries
     *
     * @return list<Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getConversationsBatch(array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        // Check cache for each query
        $results = [];
        $uncachedQueries = [];
        $uncachedIndices = [];

        foreach ($queries as $index => $params) {
            $cached = $this->cache->get($params->getCacheKey());

            if ($cached !== null) {
                /** @var list<Conversation> $cached */
                $results[$index] = $cached;
            } else {
                $uncachedQueries[] = $params;
                $uncachedIndices[] = $index;
            }
        }

        // Fetch uncached queries in parallel
        if ($uncachedQueries !== []) {
            $fetched = $this->conversationsClient->getConversationsBatch($uncachedQueries);
            $results += $this->processFetchedBatch($fetched, $uncachedQueries, $uncachedIndices);
        }

        // Sort by original index and flatten
        \ksort($results);

        /** @var list<Conversation> */
        return \array_merge(...\array_values($results));
    }

    /**
     * Process fetched batch results: validate, enrich, cache, and return indexed results.
     *
     * @param array<int, list<Conversation>> $fetched
     * @param list<ConversationQueryParams> $uncachedQueries
     * @param list<int> $uncachedIndices
     *
     * @return array<int, list<Conversation>>
     *
     * @throws ExternalServiceUnavailableException When API unavailable during enrichment
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function processFetchedBatch(
        array $fetched,
        array $uncachedQueries,
        array $uncachedIndices,
    ): array {
        $results = [];

        foreach ($fetched as $i => $conversations) {
            if (!\array_key_exists($i, $uncachedIndices) || !\array_key_exists($i, $uncachedQueries)) {
                throw new LogicException('Batch response indices must match request indices');
            }
            $originalIndex = $uncachedIndices[$i];
            $params = $uncachedQueries[$i];

            // Enrich and cache
            $enriched = $this->enricher->enrich($conversations);
            $this->cache->put($params->getCacheKey(), $enriched, $params->ttlSeconds);

            $results[$originalIndex] = $enriched;
        }

        return $results;
    }

    /**
     * Invalidate cached conversations for given params.
     */
    public function invalidateConversations(ConversationQueryParams $params): void
    {
        $this->cache->forget($params->getCacheKey());
    }

    /**
     * Get all mailboxes with caching.
     *
     * @return list<Mailbox>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getMailboxes(): array
    {
        return $this->cache->remember(
            self::KEY_MAILBOXES,
            self::SEVEN_DAYS,
            fn(): array => $this->mailboxesClient->list(),
        );
    }

    /**
     * Get escalations configuration with caching.
     *
     * @throws ConfigurationNotFoundException When config missing or disabled
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function getEscalationsConfig(): EscalationsConfig
    {
        return $this->cache->remember(
            self::KEY_ESCALATION_CONFIG,
            self::FIVE_MINUTES,
            fn(): EscalationsConfig => $this->escalationsConfigRepository->get(),
        );
    }
}
