<?php

declare(strict_types=1);

namespace App\Application\HelpScout\UseCases;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\Support\ConversationSorter;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\ConfigurationNotFoundException;

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
     *
     * @throws ConfigurationNotFoundException When escalations config missing or disabled
     * @throws ExternalServiceUnavailableException When API or database unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
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
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function executeAndProcess(array $queries, EscalationsConfig $config): array
    {
        $conversations = $this->helpScout->getConversationsBatch($queries);
        $deduplicated = self::deduplicate($conversations);

        return ConversationSorter::byPriorityHierarchy($deduplicated, $config);
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
}
