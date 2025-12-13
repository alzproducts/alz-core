<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Support;

use App\Application\HelpScout\Support\ConversationSorter;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationSorter::class)]
final class ConversationSorterTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | byStatusAndDate() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function by_status_and_date_returns_empty_array_for_empty_input(): void
    {
        $result = ConversationSorter::byStatusAndDate([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function by_status_and_date_sorts_active_before_pending(): void
    {
        $pending = $this->createConversation(1, 'pending');
        $active = $this->createConversation(2, 'active');

        $result = ConversationSorter::byStatusAndDate([$pending, $active]);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_status_and_date_sorts_pending_before_closed(): void
    {
        $closed = $this->createConversation(1, 'closed');
        $pending = $this->createConversation(2, 'pending');

        $result = ConversationSorter::byStatusAndDate([$closed, $pending]);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_status_and_date_sorts_active_before_closed(): void
    {
        $closed = $this->createConversation(1, 'closed');
        $active = $this->createConversation(2, 'active');

        $result = ConversationSorter::byStatusAndDate([$closed, $active]);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_status_and_date_sorts_same_status_by_user_updated_at_desc(): void
    {
        $older = $this->createConversation(1, 'active', userUpdatedAt: new DateTimeImmutable('2024-01-01'));
        $newer = $this->createConversation(2, 'active', userUpdatedAt: new DateTimeImmutable('2024-12-14'));

        $result = ConversationSorter::byStatusAndDate([$older, $newer]);

        $this->assertSame(2, $result[0]->id); // Newest first
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_status_and_date_falls_back_to_updated_at_when_user_updated_at_null(): void
    {
        $older = $this->createConversation(1, 'active', updatedAt: new DateTimeImmutable('2024-01-01'));
        $newer = $this->createConversation(2, 'active', updatedAt: new DateTimeImmutable('2024-12-14'));

        $result = ConversationSorter::byStatusAndDate([$older, $newer]);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_status_and_date_falls_back_to_created_at(): void
    {
        $older = $this->createConversation(1, 'active', createdAt: new DateTimeImmutable('2024-01-01'));
        $newer = $this->createConversation(2, 'active', createdAt: new DateTimeImmutable('2024-12-14'));

        $result = ConversationSorter::byStatusAndDate([$older, $newer]);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_status_and_date_sorts_multiple_conversations_correctly(): void
    {
        $closedOld = $this->createConversation(1, 'closed', createdAt: new DateTimeImmutable('2024-01-01'));
        $activeNew = $this->createConversation(2, 'active', createdAt: new DateTimeImmutable('2024-12-14'));
        $pendingMid = $this->createConversation(3, 'pending', createdAt: new DateTimeImmutable('2024-06-01'));
        $activeOld = $this->createConversation(4, 'active', createdAt: new DateTimeImmutable('2024-01-15'));

        $result = ConversationSorter::byStatusAndDate([$closedOld, $activeNew, $pendingMid, $activeOld]);

        // Active first (newest first within), then pending, then closed
        $this->assertSame(2, $result[0]->id); // active, newest
        $this->assertSame(4, $result[1]->id); // active, older
        $this->assertSame(3, $result[2]->id); // pending
        $this->assertSame(1, $result[3]->id); // closed
    }

    /*
    |--------------------------------------------------------------------------
    | byPriorityHierarchy() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function by_priority_hierarchy_returns_empty_array_for_empty_input(): void
    {
        $config = $this->createConfig();

        $result = ConversationSorter::byPriorityHierarchy([], $config);

        $this->assertSame([], $result);
    }

    #[Test]
    public function by_priority_hierarchy_sorts_priority_tagged_first(): void
    {
        $config = $this->createConfig(priorityTags: ['urgent']);

        $standard = $this->createConversation(1, 'active');
        $priority = $this->createConversation(2, 'active', tags: [new ConversationTag(1, 'urgent', 'red')]);

        $result = ConversationSorter::byPriorityHierarchy([$standard, $priority], $config);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_priority_hierarchy_sorts_assigned_before_standard(): void
    {
        $config = $this->createConfig(assignedTag: 'server to-do');

        $standard = $this->createConversation(1, 'active');
        $assigned = $this->createConversation(2, 'active', tags: [new ConversationTag(2, 'server to-do', 'blue')]);

        $result = ConversationSorter::byPriorityHierarchy([$standard, $assigned], $config);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_priority_hierarchy_sorts_priority_before_assigned(): void
    {
        $config = $this->createConfig(priorityTags: ['urgent'], assignedTag: 'server to-do');

        $assigned = $this->createConversation(1, 'active', tags: [new ConversationTag(1, 'server to-do', 'blue')]);
        $priority = $this->createConversation(2, 'active', tags: [new ConversationTag(2, 'urgent', 'red')]);

        $result = ConversationSorter::byPriorityHierarchy([$assigned, $priority], $config);

        $this->assertSame(2, $result[0]->id); // priority
        $this->assertSame(1, $result[1]->id); // assigned
    }

    #[Test]
    public function by_priority_hierarchy_sorts_same_priority_by_waiting_since_oldest_first(): void
    {
        $config = $this->createConfig();

        $newer = $this->createConversation(1, 'active', customerWaitingSince: new DateTimeImmutable('2024-12-14'));
        $older = $this->createConversation(2, 'active', customerWaitingSince: new DateTimeImmutable('2024-01-01'));

        $result = ConversationSorter::byPriorityHierarchy([$newer, $older], $config);

        $this->assertSame(2, $result[0]->id); // Oldest waiting first
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_priority_hierarchy_null_waiting_since_comes_last(): void
    {
        $config = $this->createConfig();

        $nullWaiting = $this->createConversation(1, 'active', customerWaitingSince: null);
        $hasWaiting = $this->createConversation(2, 'active', customerWaitingSince: new DateTimeImmutable('2024-12-14'));

        $result = ConversationSorter::byPriorityHierarchy([$nullWaiting, $hasWaiting], $config);

        $this->assertSame(2, $result[0]->id);
        $this->assertSame(1, $result[1]->id);
    }

    #[Test]
    public function by_priority_hierarchy_both_null_waiting_since_equal(): void
    {
        $config = $this->createConfig();

        $a = $this->createConversation(1, 'active', customerWaitingSince: null);
        $b = $this->createConversation(2, 'active', customerWaitingSince: null);

        $result = ConversationSorter::byPriorityHierarchy([$a, $b], $config);

        // Either order is acceptable when both null, but count should be preserved
        $this->assertCount(2, $result);
    }

    #[Test]
    public function by_priority_hierarchy_sorts_complex_scenario_correctly(): void
    {
        $config = $this->createConfig(priorityTags: ['urgent', 'vip'], assignedTag: 'handling');

        $standard1 = $this->createConversation(1, 'active', customerWaitingSince: new DateTimeImmutable('2024-01-01'));
        $priority1 = $this->createConversation(2, 'active', tags: [new ConversationTag(1, 'urgent', 'red')], customerWaitingSince: new DateTimeImmutable('2024-12-10'));
        $assigned1 = $this->createConversation(3, 'active', tags: [new ConversationTag(2, 'handling', 'blue')], customerWaitingSince: new DateTimeImmutable('2024-06-01'));
        $priority2 = $this->createConversation(4, 'active', tags: [new ConversationTag(3, 'vip', 'gold')], customerWaitingSince: new DateTimeImmutable('2024-06-01'));
        $standard2 = $this->createConversation(5, 'active', customerWaitingSince: new DateTimeImmutable('2024-12-14'));

        $result = ConversationSorter::byPriorityHierarchy([$standard1, $priority1, $assigned1, $priority2, $standard2], $config);

        // Priority (oldest first): 4 (June), 2 (Dec)
        // Assigned: 3
        // Standard (oldest first): 1 (Jan), 5 (Dec)
        $this->assertSame(4, $result[0]->id); // priority, oldest
        $this->assertSame(2, $result[1]->id); // priority, newer
        $this->assertSame(3, $result[2]->id); // assigned
        $this->assertSame(1, $result[3]->id); // standard, oldest
        $this->assertSame(5, $result[4]->id); // standard, newest
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<ConversationTag> $tags
     */
    private function createConversation(
        int $id,
        string $status,
        ?DateTimeImmutable $userUpdatedAt = null,
        ?DateTimeImmutable $updatedAt = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $customerWaitingSince = null,
        array $tags = [],
    ): Conversation {
        return new Conversation(
            id: $id,
            number: 1000 + $id,
            subject: "Test conversation {$id}",
            status: $status,
            mailboxId: 100,
            createdAt: $createdAt ?? new DateTimeImmutable('2024-01-01'),
            updatedAt: $updatedAt,
            userUpdatedAt: $userUpdatedAt,
            customerWaitingSince: $customerWaitingSince,
            snooze: null,
            tags: $tags,
            customer: null,
            assignee: null,
        );
    }

    /**
     * @param list<string> $priorityTags
     * @param list<string> $excludedTags
     */
    private function createConfig(
        int $lateThresholdHours = 24,
        int $latePriorityThresholdHours = 4,
        array $priorityTags = [],
        array $excludedTags = [],
        string $assignedTag = 'assigned',
    ): EscalationsConfig {
        return new EscalationsConfig(
            lateThresholdHours: $lateThresholdHours,
            latePriorityThresholdHours: $latePriorityThresholdHours,
            priorityTags: $priorityTags,
            excludedTags: $excludedTags,
            assignedTag: $assignedTag,
        );
    }
}
