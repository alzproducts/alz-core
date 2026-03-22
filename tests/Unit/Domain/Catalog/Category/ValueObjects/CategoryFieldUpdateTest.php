<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Category\ValueObjects;

use App\Domain\Catalog\Category\Enums\CategoryUpdatableField;
use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryFieldUpdate::class)]
final class CategoryFieldUpdateTest extends TestCase
{
    #[Test]
    public function title_factory_creates_correct_update(): void
    {
        $update = CategoryFieldUpdate::title('Electronics');

        self::assertSame(CategoryUpdatableField::Title, $update->field);
        self::assertSame('Electronics', $update->value);
    }
}
