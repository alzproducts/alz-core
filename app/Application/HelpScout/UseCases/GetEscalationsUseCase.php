<?php

declare(strict_types=1);

namespace App\Application\HelpScout\UseCases;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;

/**
 * Orchestrates escalation queries across mailboxes.
 *
 * Executes 5 parallel HelpScout API queries:
 * - Support mailbox: priority late + standard late
 * - Purchase Orders mailbox: priority late + standard late
 * - All mailboxes: manually assigned (by tag)
 *
 * Results are deduplicated by conversation ID, then sorted by priority hierarchy:
 * 1. Priority-tagged conversations (oldest first by waitingSince)
 * 2. Assigned-tagged conversations (oldest first by waitingSince)
 * 3. Standard late conversations (oldest first by waitingSince)
 */
final readonly class GetEscalationsUseCase
{
    public function __construct(
        private CachingHelpScoutService $helpScout,
        private int $supportMailboxId,
        private int $purchaseOrdersMailboxId,
    ) {}

    /**
     * Execute escalations queries and return sorted, deduplicated results.
     *
     * @param bool $forceRefresh Invalidate cache before fetching (for manual refresh)
     *
     * @return list<Conversation>
     */
    public function execute(bool $forceRefresh = false): array
    {
        $config = $this->helpScout->getEscalationsConfig();
        $queries = $this->buildQueries($config);

        if ($forceRefresh) {
            foreach ($queries as $params) {
                $this->helpScout->invalidateConversations($params);
            }
        }

        return $this->executeAndProcess($queries, $config);
    }

    /**
     * Build all query parameters from config.
     *
     * @return list<ConversationQueryParams>
     */
    private function buildQueries(EscalationsConfig $config): array
    {
        // Convert to lists for type safety (PHPStan requires list<string> not array<int, string>)
        $priorityTags = \array_values($config->priorityTags);
        $excludedTags = \array_values($config->excludedTags);

        return [
            // Support mailbox queries
            ConversationQueryParams::latePriority(
                mailboxId: $this->supportMailboxId,
                priorityTags: $priorityTags,
                excludedTags: $excludedTags,
                thresholdHours: $config->latePriorityThresholdHours,
            ),
            ConversationQueryParams::lateStandard(
                mailboxId: $this->supportMailboxId,
                excludedTags: $excludedTags,
                thresholdHours: $config->lateThresholdHours,
            ),
            // Purchase Orders mailbox queries
            ConversationQueryParams::latePriority(
                mailboxId: $this->purchaseOrdersMailboxId,
                priorityTags: $priorityTags,
                excludedTags: $excludedTags,
                thresholdHours: $config->latePriorityThresholdHours,
            ),
            ConversationQueryParams::lateStandard(
                mailboxId: $this->purchaseOrdersMailboxId,
                excludedTags: $excludedTags,
                thresholdHours: $config->lateThresholdHours,
            ),
            // Manually assigned across all mailboxes
            ConversationQueryParams::manuallyAssigned($config->assignedTag),
        ];
    }

    /**
     * Execute queries and process results.
     *
     * Uses batch fetching for parallel execution at the Infrastructure layer.
     *
     * @param list<ConversationQueryParams> $queries
     *
     * @return list<Conversation>
     */
    private function executeAndProcess(array $queries, EscalationsConfig $config): array
    {
        $conversations = $this->helpScout->getConversationsBatch($queries);
        $deduplicated = self::deduplicate($conversations);

        return self::sortByPriorityHierarchy($deduplicated, $config);
    }

    /**
     * Remove duplicate conversations by ID, keeping first occurrence.
     *
     * @param list<Conversation> $conversations
     *
     * @return list<Conversation>
     */
    private static function deduplicate(array $conversations): array
    {
        $unique = [];

        foreach ($conversations as $conversation) {
            $unique[$conversation->id] ??= $conversation;
        }

        return \array_values($unique);
    }

    /**
     * Sort conversations by priority hierarchy.
     *
     * Priority order:
     * 1. Conversations with priority tags (oldest waitingSince first)
     * 2. Conversations with assigned tag (oldest waitingSince first)
     * 3. Standard conversations (oldest waitingSince first)
     *
     * @param list<Conversation> $conversations
     *
     * @return list<Conversation>
     */
    private static function sortByPriorityHierarchy(array $conversations, EscalationsConfig $config): array
    {
        \usort($conversations, static function (Conversation $a, Conversation $b) use ($config): int {
            $priorityA = self::getPriorityLevel($a, $config);
            $priorityB = self::getPriorityLevel($b, $config);

            // Higher priority (lower number) comes first
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            // Within same priority, oldest waitingSince first
            return self::compareWaitingSince($a, $b);
        });

        return $conversations;
    }

    /**
     * Determine priority level for a conversation.
     *
     * @return int 1 = priority tag, 2 = assigned tag, 3 = standard
     */
    private static function getPriorityLevel(Conversation $conversation, EscalationsConfig $config): int
    {
        if (\array_any($conversation->tags, static fn(ConversationTag $tag): bool => $config->isPriorityTag($tag->name))) {
            return 1;
        }

        if (\array_any($conversation->tags, static fn(ConversationTag $tag): bool => $config->isAssignedTag($tag->name))) {
            return 2;
        }

        return 3;
    }

    /**
     * Compare two conversations by waitingSince (oldest first).
     *
     * Null waitingSince treated as most recent (lowest priority within group).
     */
    private static function compareWaitingSince(Conversation $a, Conversation $b): int
    {
        $waitingA = $a->customerWaitingSince;
        $waitingB = $b->customerWaitingSince;

        // Null = most recent, should come last
        if (($waitingA === null) && ($waitingB === null)) {
            return 0;
        }

        if ($waitingA === null) {
            return 1;
        }

        if ($waitingB === null) {
            return -1;
        }

        return $waitingA <=> $waitingB;
    }
}
