<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Services;

use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\Support\CacheTimesTrait;
use App\Application\Support\GracefulCache;
use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\CustomerService\ValueObjects\Mailbox;

/**
 * Caching decorator for HelpScout API operations.
 *
 * Adds caching layer to HelpScout endpoint clients without modifying
 * the underlying implementations. Uses GracefulCache for resilient caching
 * that degrades gracefully on backend failures.
 *
 * Cache Strategy:
 * - Agent mappings: 7 days TTL (user→agent rarely changes)
 * - Mailboxes: 7 days TTL (rarely changes)
 * - Conversations: 5 minutes TTL (frequently updated)
 * - Escalation config: 5 minutes TTL (admin-configurable)
 */
final readonly class CachingHelpScoutService
{
    use CacheTimesTrait;

    public const string CACHE_PREFIX = 'helpscout';

    private const string KEY_AGENT_BY_EMAIL = self::CACHE_PREFIX . ':agent:email:';

    private const string KEY_MAILBOXES = self::CACHE_PREFIX . ':mailboxes';

    private const string KEY_ESCALATION_CONFIG = self::CACHE_PREFIX . ':escalation-config';

    public function __construct(
        private ConversationsClientInterface $conversationsClient,
        private AgentsClientInterface $agentsClient,
        private MailboxesClientInterface $mailboxesClient,
        private EscalationsConfigRepositoryInterface $escalationsConfigRepository,
        private GracefulCache $cache,
    ) {}

    /**
     * Resolve authenticated user email to HelpScout agent ID.
     *
     * Normalizes email to lowercase and caches the mapping for 7 days.
     *
     * @throws CustomerServiceAgentNotFoundException When no agent matches email
     */
    public function resolveAgentId(string $email): int
    {
        $normalizedEmail = \mb_strtolower(\mb_trim($email));
        $cacheKey = self::KEY_AGENT_BY_EMAIL . \hash('xxh3', $normalizedEmail);

        $agentId = $this->cache->remember(
            $cacheKey,
            self::SEVEN_DAYS,
            fn(): ?int => $this->agentsClient->findByEmail($normalizedEmail)?->id,
        );

        if ($agentId === null) {
            throw new CustomerServiceAgentNotFoundException($normalizedEmail);
        }

        return $agentId;
    }

    /**
     * Get conversations assigned to an agent.
     *
     * @return array<int, Conversation>
     */
    public function getAssignedConversations(int $agentId, string $status = 'active'): array
    {
        return $this->conversationsClient->getAssignedTo($agentId, $status);
    }

    /**
     * Get todo conversations for an agent (tagged with assigned tag).
     *
     * @return array<int, Conversation>
     */
    public function getTodosForAgent(int $agentId): array
    {
        $config = $this->getEscalationsConfig();

        return $this->conversationsClient->getWithTagForAgent($agentId, $config->assignedTag);
    }

    /**
     * Get conversations tagged as negative reviews.
     *
     * @return array<int, Conversation>
     */
    public function getNegativeReviewConversations(string $tag = 'negative-feedback'): array
    {
        return $this->conversationsClient->getWithTag($tag);
    }

    /**
     * Get all mailboxes with caching.
     *
     * @return array<int, Mailbox>
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
     */
    public function getEscalationsConfig(): EscalationsConfig
    {
        return $this->cache->remember(
            self::KEY_ESCALATION_CONFIG,
            self::FIVE_MINUTES,
            fn(): EscalationsConfig => $this->escalationsConfigRepository->get(),
        );
    }

    /**
     * Invalidate agent email cache (e.g., when agent email changes).
     */
    public function invalidateAgentCache(string $email): void
    {
        $normalizedEmail = \mb_strtolower(\mb_trim($email));
        $cacheKey = self::KEY_AGENT_BY_EMAIL . \hash('xxh3', $normalizedEmail);
        $this->cache->forget($cacheKey);
    }

    /**
     * Invalidate mailboxes cache.
     */
    public function invalidateMailboxes(): void
    {
        $this->cache->forget(self::KEY_MAILBOXES);
    }

    /**
     * Invalidate escalations config cache.
     */
    public function invalidateEscalationsConfig(): void
    {
        $this->cache->forget(self::KEY_ESCALATION_CONFIG);
    }

    /**
     * Invalidate all HelpScout caches.
     */
    public function invalidateAll(): void
    {
        $this->invalidateMailboxes();
        $this->invalidateEscalationsConfig();
        // Agent caches expire naturally (no email tracking needed)
    }
}
