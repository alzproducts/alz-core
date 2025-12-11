<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * Configuration for customer service escalation thresholds.
 *
 * Defines when conversations are considered "late" or require priority handling.
 * Used by dashboard widgets to surface conversations needing attention.
 *
 * @template-pattern Domain Value Object
 */
final readonly class EscalationsConfig
{
    /**
     * @param int $lateThresholdHours Hours before a conversation is considered "late"
     * @param int $latePriorityThresholdHours Hours for priority conversations (shorter threshold)
     * @param array<int, string> $priorityTags Tags that indicate priority conversations
     * @param array<int, string> $excludedTags Tags that exclude conversations from escalations
     * @param string $assignedTag Tag indicating conversation is actively being handled
     *
     * @throws InvalidArgumentException When thresholds are invalid
     */
    public function __construct(
        public int $lateThresholdHours,
        public int $latePriorityThresholdHours,
        public array $priorityTags,
        public array $excludedTags,
        public string $assignedTag,
    ) {
        Assert::greaterThan($lateThresholdHours, 0, 'Late threshold must be positive hours');
        Assert::greaterThan($latePriorityThresholdHours, 0, 'Late priority threshold must be positive hours');
        Assert::lessThanEq(
            $latePriorityThresholdHours,
            $lateThresholdHours,
            'Priority threshold should be <= standard threshold',
        );
        Assert::notEmpty($assignedTag, 'Assigned tag cannot be empty');
    }

    /**
     * Check if a tag is in the priority list.
     */
    public function isPriorityTag(string $tag): bool
    {
        return \in_array($tag, $this->priorityTags, true);
    }

    /**
     * Check if a tag excludes the conversation from escalations.
     */
    public function isExcludedTag(string $tag): bool
    {
        return \in_array($tag, $this->excludedTags, true);
    }

    /**
     * Check if a conversation has the assigned tag (being actively handled).
     */
    public function isAssignedTag(string $tag): bool
    {
        return $tag === $this->assignedTag;
    }
}
