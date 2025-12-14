<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Support;

use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;

/**
 * Sorting utilities for conversation collections.
 *
 * Provides reusable sorting methods for use cases that need to order conversations.
 * Each method returns a new sorted array without modifying the original.
 */
final class ConversationSorter
{
    /**
     * Status priority mapping: active=0, pending=1, other=2.
     *
     * @var array<string, int>
     */
    private const array STATUS_PRIORITY = [
        'active' => 0,
        'pending' => 1,
    ];

    /**
     * Sort by status priority, then by newest update time.
     *
     * Status order: active → pending → closed/other
     * Within same status: newest userUpdatedAt/updatedAt/createdAt first
     *
     * @param list<Conversation> $conversations
     *
     * @return list<Conversation>
     */
    public static function byStatusAndDate(array $conversations): array
    {
        if ($conversations === []) {
            return [];
        }

        \usort($conversations, static function (Conversation $a, Conversation $b): int {
            $statusA = self::STATUS_PRIORITY[$a->status] ?? 2;
            $statusB = self::STATUS_PRIORITY[$b->status] ?? 2;

            if ($statusA !== $statusB) {
                return $statusA <=> $statusB;
            }

            // Within same status, sort by update time (newest first)
            $timeA = $a->userUpdatedAt ?? $a->updatedAt ?? $a->createdAt;
            $timeB = $b->userUpdatedAt ?? $b->updatedAt ?? $b->createdAt;

            return $timeB <=> $timeA;
        });

        return $conversations;
    }

    /**
     * Sort by escalation priority hierarchy.
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
    public static function byPriorityHierarchy(array $conversations, EscalationsConfig $config): array
    {
        if ($conversations === []) {
            return [];
        }

        \usort($conversations, static function (Conversation $a, Conversation $b) use ($config): int {
            $priorityA = self::getPriorityLevel($a, $config);
            $priorityB = self::getPriorityLevel($b, $config);

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
