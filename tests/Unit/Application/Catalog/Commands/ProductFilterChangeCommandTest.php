<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\Commands;

use App\Application\Catalog\Commands\ProductFilterChangeCommand;
use App\Domain\Catalog\Product\Enums\RatingFilterValue;
use App\Domain\ValueObjects\IntId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProductFilterChangeCommand::class)]
final class ProductFilterChangeCommandTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | filterValuesForDispatch() — non-empty values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function filter_values_for_dispatch_returns_values_as_is_when_multiple_values_present(): void
    {
        $values = [RatingFilterValue::FourStars, RatingFilterValue::FourAndHalfStars];

        $command = new ProductFilterChangeCommand(IntId::from(1), 15, $values);

        $this->assertSame($values, $command->filterValuesForDispatch());
    }

    #[Test]
    public function filter_values_for_dispatch_returns_values_as_is_when_single_value_present(): void
    {
        $values = [RatingFilterValue::FourStars];

        $command = new ProductFilterChangeCommand(IntId::from(1), 15, $values);

        $this->assertSame($values, $command->filterValuesForDispatch());
    }

    /*
    |--------------------------------------------------------------------------
    | filterValuesForDispatch() — empty values (removal case)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function filter_values_for_dispatch_returns_null_when_filter_values_are_empty(): void
    {
        $command = new ProductFilterChangeCommand(IntId::from(1), 15, []);

        $this->assertNull($command->filterValuesForDispatch());
    }
}
