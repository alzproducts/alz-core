<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(EscalationsConfig::class)]
final class EscalationsConfigTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_valid_escalations_config(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: ['urgent', 'vip'],
            excludedTags: ['spam', 'closed'],
            assignedTag: 'assigned',
        );

        $this->assertSame(24, $config->lateThresholdHours);
        $this->assertSame(4, $config->latePriorityThresholdHours);
        $this->assertSame(['urgent', 'vip'], $config->priorityTags);
        $this->assertSame(['spam', 'closed'], $config->excludedTags);
        $this->assertSame('assigned', $config->assignedTag);
    }

    #[Test]
    public function it_creates_config_with_empty_tag_arrays(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 48,
            latePriorityThresholdHours: 12,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'in-progress',
        );

        $this->assertSame([], $config->priorityTags);
        $this->assertSame([], $config->excludedTags);
    }

    #[Test]
    public function it_accepts_equal_thresholds(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 8,
            latePriorityThresholdHours: 8,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'assigned',
        );

        $this->assertSame(8, $config->lateThresholdHours);
        $this->assertSame(8, $config->latePriorityThresholdHours);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_zero_late_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Late threshold must be positive hours');

        new EscalationsConfig(
            lateThresholdHours: 0,
            latePriorityThresholdHours: 4,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'assigned',
        );
    }

    #[Test]
    public function it_rejects_negative_late_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Late threshold must be positive hours');

        new EscalationsConfig(
            lateThresholdHours: -1,
            latePriorityThresholdHours: 4,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'assigned',
        );
    }

    #[Test]
    public function it_rejects_zero_priority_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Late priority threshold must be positive hours');

        new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 0,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'assigned',
        );
    }

    #[Test]
    public function it_rejects_negative_priority_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Late priority threshold must be positive hours');

        new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: -1,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'assigned',
        );
    }

    #[Test]
    public function it_rejects_priority_threshold_greater_than_standard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority threshold should be <= standard threshold');

        new EscalationsConfig(
            lateThresholdHours: 4,
            latePriorityThresholdHours: 8,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'assigned',
        );
    }

    #[Test]
    public function it_rejects_empty_assigned_tag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Assigned tag cannot be empty');

        new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: [],
            excludedTags: [],
            assignedTag: '',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_priority_tag_returns_true_for_priority_tag(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: ['urgent', 'vip', 'priority'],
            excludedTags: [],
            assignedTag: 'assigned',
        );

        $this->assertTrue($config->isPriorityTag('urgent'));
        $this->assertTrue($config->isPriorityTag('vip'));
        $this->assertTrue($config->isPriorityTag('priority'));
    }

    #[Test]
    public function is_priority_tag_returns_false_for_non_priority_tag(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: ['urgent', 'vip'],
            excludedTags: [],
            assignedTag: 'assigned',
        );

        $this->assertFalse($config->isPriorityTag('normal'));
        $this->assertFalse($config->isPriorityTag('low'));
        $this->assertFalse($config->isPriorityTag(''));
    }

    #[Test]
    public function is_excluded_tag_returns_true_for_excluded_tag(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: [],
            excludedTags: ['spam', 'closed', 'resolved'],
            assignedTag: 'assigned',
        );

        $this->assertTrue($config->isExcludedTag('spam'));
        $this->assertTrue($config->isExcludedTag('closed'));
        $this->assertTrue($config->isExcludedTag('resolved'));
    }

    #[Test]
    public function is_excluded_tag_returns_false_for_non_excluded_tag(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: [],
            excludedTags: ['spam', 'closed'],
            assignedTag: 'assigned',
        );

        $this->assertFalse($config->isExcludedTag('open'));
        $this->assertFalse($config->isExcludedTag('pending'));
        $this->assertFalse($config->isExcludedTag(''));
    }

    #[Test]
    public function is_assigned_tag_returns_true_for_matching_tag(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'in-progress',
        );

        $this->assertTrue($config->isAssignedTag('in-progress'));
    }

    #[Test]
    public function is_assigned_tag_returns_false_for_non_matching_tag(): void
    {
        $config = new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: [],
            excludedTags: [],
            assignedTag: 'in-progress',
        );

        $this->assertFalse($config->isAssignedTag('assigned'));
        $this->assertFalse($config->isAssignedTag('In-Progress'));
        $this->assertFalse($config->isAssignedTag(''));
    }
}
