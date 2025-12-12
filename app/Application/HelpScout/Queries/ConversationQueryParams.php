<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Queries;

/**
 * Value object encapsulating conversation query parameters.
 *
 * Owns cache key generation to ensure params and keys stay in sync.
 * Use static factories to create instances for specific query types.
 */
final readonly class ConversationQueryParams
{
    private const string CACHE_PREFIX = 'helpscout:conversations';

    private const int DEFAULT_TTL_SECONDS = 300;

    private function __construct(
        public string $queryName,
        public int $ttlSeconds,
        public ?int $agentId = null,
        public ?string $status = null,
        public ?string $tag = null,
        public ?int $mailboxId = null,
        public ?string $waitingSince = null,
    ) {}

    /**
     * Generate cache key from parameters.
     *
     * Filters null values and builds deterministic key string.
     */
    public function getCacheKey(): string
    {
        $params = \array_filter([
            'agent' => $this->agentId,
            'status' => $this->status,
            'tag' => $this->tag,
            'mailbox' => $this->mailboxId,
            'since' => $this->waitingSince,
        ], static fn(mixed $v): bool => $v !== null);

        $paramString = ($params !== []) ? (':' . \http_build_query($params)) : '';

        return self::CACHE_PREFIX . ":{$this->queryName}{$paramString}";
    }

    /**
     * Create params for assigned conversations query.
     *
     * @param int $agentId HelpScout agent ID
     * @param string $status Conversation status filter (default: 'active')
     */
    public static function assigned(int $agentId, string $status = 'active'): self
    {
        return new self(
            queryName: 'assigned',
            ttlSeconds: self::DEFAULT_TTL_SECONDS,
            agentId: $agentId,
            status: $status,
        );
    }

    /**
     * Create params for todos query (agent + tag).
     *
     * @param int $agentId HelpScout agent ID
     * @param string $tag Tag from EscalationsConfig->assignedTag
     */
    public static function todos(int $agentId, string $tag): self
    {
        return new self(
            queryName: 'todos',
            ttlSeconds: self::DEFAULT_TTL_SECONDS,
            agentId: $agentId,
            tag: $tag,
        );
    }

    /**
     * Create params for negative reviews query.
     *
     * @param string $tag Configurable tag for negative feedback conversations
     */
    public static function negativeReviews(string $tag): self
    {
        return new self(
            queryName: 'negative-reviews',
            ttlSeconds: self::DEFAULT_TTL_SECONDS,
            tag: $tag,
        );
    }

    /**
     * Create params for waiting-since query (used by escalations).
     *
     * @param int $mailboxId HelpScout mailbox ID
     * @param string $since ISO 8601 datetime for customer waiting threshold
     */
    public static function waitingSince(int $mailboxId, string $since): self
    {
        return new self(
            queryName: 'waiting-since',
            ttlSeconds: self::DEFAULT_TTL_SECONDS,
            mailboxId: $mailboxId,
            waitingSince: $since,
        );
    }
}
