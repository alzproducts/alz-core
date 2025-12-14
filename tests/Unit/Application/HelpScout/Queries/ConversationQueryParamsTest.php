<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Queries;

use App\Application\HelpScout\Queries\Conversation\Enums\SortField;
use App\Application\HelpScout\Queries\Conversation\Enums\SortOrder;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationQueryParams::class)]
final class ConversationQueryParamsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | assigned() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function assigned_creates_params_with_agent_id(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);

        $this->assertSame(12345, $params->agentId);
    }

    #[Test]
    public function assigned_sets_status_to_active_pending(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 100);

        $this->assertSame('active,pending', $params->status);
    }

    #[Test]
    public function assigned_sets_query_name(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 100);

        $this->assertSame('assigned', $params->queryName);
    }

    #[Test]
    public function assigned_sets_default_ttl(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 100);

        $this->assertSame(300, $params->ttlSeconds);
    }

    #[Test]
    public function assigned_does_not_set_tag_or_mailbox(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 100);

        $this->assertNull($params->tag);
        $this->assertNull($params->mailboxId);
        $this->assertNull($params->query);
        $this->assertNull($params->sortField);
        $this->assertNull($params->sortOrder);
    }

    /*
    |--------------------------------------------------------------------------
    | todos() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function todos_creates_params_with_agent_id_and_tag(): void
    {
        $params = ConversationQueryParams::todos(agentId: 67890);

        $this->assertSame(67890, $params->agentId);
        $this->assertSame('server to-do', $params->tag);
    }

    #[Test]
    public function todos_sets_status_including_closed(): void
    {
        $params = ConversationQueryParams::todos(agentId: 100);

        $this->assertSame('active,pending,closed', $params->status);
    }

    #[Test]
    public function todos_sets_query_name(): void
    {
        $params = ConversationQueryParams::todos(agentId: 100);

        $this->assertSame('todos', $params->queryName);
    }

    /*
    |--------------------------------------------------------------------------
    | negativeReviews() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function negative_reviews_creates_params_without_agent_id(): void
    {
        $params = ConversationQueryParams::negativeReviews();

        $this->assertNull($params->agentId);
    }

    #[Test]
    public function negative_reviews_sets_tag(): void
    {
        $params = ConversationQueryParams::negativeReviews();

        $this->assertSame('feedback-review-negative', $params->tag);
    }

    #[Test]
    public function negative_reviews_sets_status_to_active_only(): void
    {
        $params = ConversationQueryParams::negativeReviews();

        $this->assertSame('active', $params->status);
    }

    #[Test]
    public function negative_reviews_sets_query_name(): void
    {
        $params = ConversationQueryParams::negativeReviews();

        $this->assertSame('negative-reviews', $params->queryName);
    }

    /*
    |--------------------------------------------------------------------------
    | latePriority() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function late_priority_creates_params_with_mailbox_id(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 99999,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );

        $this->assertSame(99999, $params->mailboxId);
    }

    #[Test]
    public function late_priority_sets_tag_from_priority_tags(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent', 'priority', 'vip'],
            excludedTags: [],
            thresholdHours: 4,
        );

        $this->assertSame('urgent,priority,vip', $params->tag);
    }

    #[Test]
    public function late_priority_sets_sort_field_and_order(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 12,
        );

        $this->assertSame(SortField::WaitingSince, $params->sortField);
        $this->assertSame(SortOrder::Asc, $params->sortOrder);
    }

    #[Test]
    public function late_priority_builds_query_with_waiting_filter(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );

        $this->assertStringContainsString('waitingSince:[* TO NOW-24HOUR]', $params->query);
    }

    #[Test]
    public function late_priority_builds_query_with_excluded_tags(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: ['spam', 'on-hold'],
            thresholdHours: 24,
        );

        $this->assertStringContainsString('-tag:"spam"', $params->query);
        $this->assertStringContainsString('-tag:"on-hold"', $params->query);
        $this->assertStringContainsString(' AND ', $params->query);
    }

    #[Test]
    public function late_priority_sets_status_to_active(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );

        $this->assertSame('active', $params->status);
    }

    #[Test]
    public function late_priority_sets_query_name(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );

        $this->assertSame('late-priority', $params->queryName);
    }

    /*
    |--------------------------------------------------------------------------
    | lateStandard() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function late_standard_creates_params_with_mailbox_id(): void
    {
        $params = ConversationQueryParams::lateStandard(
            mailboxId: 88888,
            excludedTags: [],
            thresholdHours: 48,
        );

        $this->assertSame(88888, $params->mailboxId);
    }

    #[Test]
    public function late_standard_does_not_set_tag(): void
    {
        $params = ConversationQueryParams::lateStandard(
            mailboxId: 100,
            excludedTags: [],
            thresholdHours: 48,
        );

        $this->assertNull($params->tag);
    }

    #[Test]
    public function late_standard_builds_query_with_waiting_filter(): void
    {
        $params = ConversationQueryParams::lateStandard(
            mailboxId: 100,
            excludedTags: [],
            thresholdHours: 72,
        );

        $this->assertStringContainsString('waitingSince:[* TO NOW-72HOUR]', $params->query);
    }

    #[Test]
    public function late_standard_sets_query_name(): void
    {
        $params = ConversationQueryParams::lateStandard(
            mailboxId: 100,
            excludedTags: [],
            thresholdHours: 48,
        );

        $this->assertSame('late-standard', $params->queryName);
    }

    /*
    |--------------------------------------------------------------------------
    | manuallyAssigned() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function manually_assigned_creates_params_with_tag(): void
    {
        $params = ConversationQueryParams::manuallyAssigned(assignedTag: 'server to-do');

        $this->assertSame('server to-do', $params->tag);
    }

    #[Test]
    public function manually_assigned_does_not_set_mailbox_id(): void
    {
        $params = ConversationQueryParams::manuallyAssigned(assignedTag: 'assigned');

        $this->assertNull($params->mailboxId);
        $this->assertNull($params->agentId);
    }

    #[Test]
    public function manually_assigned_sets_sort_field_and_order(): void
    {
        $params = ConversationQueryParams::manuallyAssigned(assignedTag: 'handling');

        $this->assertSame(SortField::WaitingSince, $params->sortField);
        $this->assertSame(SortOrder::Asc, $params->sortOrder);
    }

    #[Test]
    public function manually_assigned_sets_query_name(): void
    {
        $params = ConversationQueryParams::manuallyAssigned(assignedTag: 'handling');

        $this->assertSame('manually-assigned', $params->queryName);
    }

    /*
    |--------------------------------------------------------------------------
    | getCacheKey() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_cache_key_includes_prefix(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);

        $this->assertStringStartsWith('helpscout:conversations:', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_includes_query_name(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);

        $this->assertStringContainsString(':assigned:', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_includes_agent_id(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);

        $this->assertStringContainsString('agent=12345', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_includes_status(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 100);

        // URL encoded comma
        $this->assertStringContainsString('status=active%2Cpending', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_includes_tag_when_set(): void
    {
        $params = ConversationQueryParams::todos(agentId: 100);

        // URL encoded space
        $this->assertStringContainsString('tag=server+to-do', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_includes_mailbox_when_set(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 99999,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );

        $this->assertStringContainsString('mailbox=99999', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_hashes_query_string(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );

        // Query should be hashed (xxh3), not raw query syntax
        $this->assertStringContainsString('query=', $params->getCacheKey());
        // Raw query contains brackets and HOUR - these shouldn't appear
        $this->assertStringNotContainsString('[* TO NOW-', $params->getCacheKey());
        $this->assertStringNotContainsString('HOUR]', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_includes_sort_when_set(): void
    {
        $params = ConversationQueryParams::latePriority(
            mailboxId: 100,
            priorityTags: ['urgent'],
            excludedTags: [],
            thresholdHours: 24,
        );

        $this->assertStringContainsString('sort=waitingSince', $params->getCacheKey());
        $this->assertStringContainsString('order=asc', $params->getCacheKey());
    }

    #[Test]
    public function get_cache_key_is_deterministic(): void
    {
        $params1 = ConversationQueryParams::assigned(agentId: 12345);
        $params2 = ConversationQueryParams::assigned(agentId: 12345);

        $this->assertSame($params1->getCacheKey(), $params2->getCacheKey());
    }

    #[Test]
    public function get_cache_key_differs_by_agent_id(): void
    {
        $params1 = ConversationQueryParams::assigned(agentId: 111);
        $params2 = ConversationQueryParams::assigned(agentId: 222);

        $this->assertNotSame($params1->getCacheKey(), $params2->getCacheKey());
    }

    #[Test]
    public function get_cache_key_differs_by_query_type(): void
    {
        $params1 = ConversationQueryParams::assigned(agentId: 100);
        $params2 = ConversationQueryParams::todos(agentId: 100);

        $this->assertNotSame($params1->getCacheKey(), $params2->getCacheKey());
    }

    #[Test]
    public function negative_reviews_cache_key_has_no_agent(): void
    {
        $params = ConversationQueryParams::negativeReviews();

        $this->assertStringNotContainsString('agent=', $params->getCacheKey());
    }
}
