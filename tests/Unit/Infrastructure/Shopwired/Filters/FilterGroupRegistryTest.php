<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Filters;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Infrastructure\Shopwired\Filters\FilterGroupRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilterGroupRegistry::class)]
final class FilterGroupRegistryTest extends TestCase
{
    // ========================================================================
    // fromDefinitions Factory
    // ========================================================================

    #[Test]
    public function it_creates_registry_from_definitions(): void
    {
        $definitions = [
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0),
            new FilterGroupDefinition(id: 2, title: 'Colour', optionNo: 2, sortOrder: 1),
        ];

        $registry = FilterGroupRegistry::fromDefinitions($definitions);

        self::assertSame(2, $registry->count());
    }

    #[Test]
    public function it_creates_empty_registry_from_empty_array(): void
    {
        $registry = FilterGroupRegistry::fromDefinitions([]);

        self::assertSame(0, $registry->count());
    }

    // ========================================================================
    // findByOptionNo
    // ========================================================================

    #[Test]
    public function find_by_option_no_returns_definition_when_exists(): void
    {
        $sizeDefinition = new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 10, sortOrder: 0);
        $registry = FilterGroupRegistry::fromDefinitions([$sizeDefinition]);

        $found = $registry->findByOptionNo(10);

        self::assertNotNull($found);
        self::assertSame('Size', $found->title);
        self::assertSame(10, $found->optionNo);
    }

    #[Test]
    public function find_by_option_no_returns_null_when_not_found(): void
    {
        $registry = FilterGroupRegistry::fromDefinitions([
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0),
        ]);

        $found = $registry->findByOptionNo(999);

        self::assertNull($found);
    }

    #[Test]
    public function find_by_option_no_returns_null_for_empty_registry(): void
    {
        $registry = FilterGroupRegistry::fromDefinitions([]);

        self::assertNull($registry->findByOptionNo(1));
    }

    // ========================================================================
    // has
    // ========================================================================

    #[Test]
    public function has_returns_true_when_option_no_exists(): void
    {
        $registry = FilterGroupRegistry::fromDefinitions([
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 5, sortOrder: 0),
        ]);

        self::assertTrue($registry->has(5));
    }

    #[Test]
    public function has_returns_false_when_option_no_missing(): void
    {
        $registry = FilterGroupRegistry::fromDefinitions([
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 5, sortOrder: 0),
        ]);

        self::assertFalse($registry->has(99));
    }

    // ========================================================================
    // Duplicate optionNo Handling
    // ========================================================================

    #[Test]
    public function last_definition_wins_on_duplicate_option_no(): void
    {
        // Edge case: if ShopWired returns duplicate optionNo (shouldn't happen),
        // the last one should win
        $first = new FilterGroupDefinition(id: 1, title: 'First', optionNo: 1, sortOrder: 0);
        $second = new FilterGroupDefinition(id: 2, title: 'Second', optionNo: 1, sortOrder: 1);

        $registry = FilterGroupRegistry::fromDefinitions([$first, $second]);

        $found = $registry->findByOptionNo(1);

        self::assertNotNull($found);
        self::assertSame('Second', $found->title);
        self::assertSame(1, $registry->count());
    }
}
