<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Filters\ValueObjects;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilterGroupDefinition::class)]
final class FilterGroupDefinitionTest extends TestCase
{
    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_valid_filter_group(): void
    {
        $definition = new FilterGroupDefinition(
            id: 123,
            title: 'Size',
            optionNo: 1,
            sortOrder: 0,
        );

        self::assertSame(123, $definition->id);
        self::assertSame('Size', $definition->title);
        self::assertSame(1, $definition->optionNo);
        self::assertSame(0, $definition->sortOrder);
    }

    #[Test]
    public function it_allows_negative_sort_order(): void
    {
        // Negative sort order is valid (could mean "show first")
        $definition = new FilterGroupDefinition(
            id: 1,
            title: 'Priority Filter',
            optionNo: 1,
            sortOrder: -1,
        );

        self::assertSame(-1, $definition->sortOrder);
    }

    #[Test]
    public function it_allows_zero_sort_order(): void
    {
        $definition = new FilterGroupDefinition(
            id: 1,
            title: 'First Filter',
            optionNo: 1,
            sortOrder: 0,
        );

        self::assertSame(0, $definition->sortOrder);
    }

    // ========================================================================
    // Validation
    // ========================================================================

    #[Test]
    public function it_rejects_non_positive_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Filter group ID must be positive');

        new FilterGroupDefinition(
            id: 0,
            title: 'Size',
            optionNo: 1,
            sortOrder: 0,
        );
    }

    #[Test]
    public function it_rejects_negative_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Filter group ID must be positive');

        new FilterGroupDefinition(
            id: -1,
            title: 'Size',
            optionNo: 1,
            sortOrder: 0,
        );
    }

    #[Test]
    public function it_rejects_empty_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Filter group title cannot be empty');

        new FilterGroupDefinition(
            id: 1,
            title: '',
            optionNo: 1,
            sortOrder: 0,
        );
    }

    #[Test]
    public function it_rejects_non_positive_option_no(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Filter group optionNo must be positive');

        new FilterGroupDefinition(
            id: 1,
            title: 'Size',
            optionNo: 0,
            sortOrder: 0,
        );
    }

    #[Test]
    public function it_rejects_negative_option_no(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Filter group optionNo must be positive');

        new FilterGroupDefinition(
            id: 1,
            title: 'Size',
            optionNo: -1,
            sortOrder: 0,
        );
    }
}
