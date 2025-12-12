<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Queries;

use App\Application\HelpScout\Queries\Conversation\Enums\SortField;
use App\Application\HelpScout\Queries\Conversation\Enums\SortOrder;

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
        public ?string $query = null,
        public ?SortField $sortField = null,
        public ?SortOrder $sortOrder = null,
    ) {}

    /**
     * Generate cache key from parameters.
     *
     * Filters null values and builds deterministic key string.
     * Query strings are hashed to avoid overly long cache keys.
     */
    public function getCacheKey(): string
    {
        $params = \array_filter([
            'agent' => $this->agentId,
            'status' => $this->status,
            'tag' => $this->tag,
            'mailbox' => $this->mailboxId,
            'query' => ($this->query !== null) ? \hash('xxh3', $this->query) : null,
            'sort' => $this->sortField?->value,
            'order' => $this->sortOrder?->value,
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
     * Create params for late priority conversations (escalations).
     *
     * Filters by mailbox, priority tags, and waiting time threshold.
     * Excludes conversations with excluded tags.
     *
     * @param int $mailboxId HelpScout mailbox ID
     * @param list<string> $priorityTags Tags indicating priority conversations
     * @param list<string> $excludedTags Tags to exclude from results
     * @param int $thresholdHours Hours threshold for "late" status
     */
    public static function latePriority(
        int $mailboxId,
        array $priorityTags,
        array $excludedTags,
        int $thresholdHours,
    ): self {
        return new self(
            queryName: 'late-priority',
            ttlSeconds: self::DEFAULT_TTL_SECONDS,
            status: 'active',
            tag: \implode(',', $priorityTags),
            mailboxId: $mailboxId,
            query: self::buildWaitingQuery($thresholdHours, $excludedTags),
            sortField: SortField::WaitingSince,
            sortOrder: SortOrder::Asc,
        );
    }

    /**
     * Create params for late standard conversations (escalations).
     *
     * Filters by mailbox and waiting time threshold.
     * Excludes conversations with excluded tags.
     *
     * @param int $mailboxId HelpScout mailbox ID
     * @param list<string> $excludedTags Tags to exclude from results
     * @param int $thresholdHours Hours threshold for "late" status
     */
    public static function lateStandard(
        int $mailboxId,
        array $excludedTags,
        int $thresholdHours,
    ): self {
        return new self(
            queryName: 'late-standard',
            ttlSeconds: self::DEFAULT_TTL_SECONDS,
            status: 'active',
            mailboxId: $mailboxId,
            query: self::buildWaitingQuery($thresholdHours, $excludedTags),
            sortField: SortField::WaitingSince,
            sortOrder: SortOrder::Asc,
        );
    }

    /**
     * Create params for manually assigned conversations (escalations).
     *
     * Filters by assigned tag across all mailboxes.
     *
     * @param string $assignedTag Tag indicating manual assignment
     */
    public static function manuallyAssigned(string $assignedTag): self
    {
        return new self(
            queryName: 'manually-assigned',
            ttlSeconds: self::DEFAULT_TTL_SECONDS,
            status: 'active',
            tag: $assignedTag,
            sortField: SortField::WaitingSince,
            sortOrder: SortOrder::Asc,
        );
    }

    /**
     * Build HelpScout query string for waiting time filtering with tag exclusions.
     *
     * @param int $thresholdHours Hours threshold
     * @param list<string> $excludedTags Tags to exclude
     *
     * @return string HelpScout query syntax
     */
    private static function buildWaitingQuery(int $thresholdHours, array $excludedTags): string
    {
        $timeFilter = "waitingSince:[* TO NOW-{$thresholdHours}HOUR]";

        if ($excludedTags === []) {
            return "({$timeFilter})";
        }

        $exclusions = \array_map(
            static fn(string $tag): string => "-tag:\"{$tag}\"",
            $excludedTags,
        );

        return "({$timeFilter} AND " . \implode(' AND ', $exclusions) . ')';
    }
}
