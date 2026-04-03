<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\DTOs;

use App\Application\Catalog\DTOs\ProductFilterChangeDTO;
use App\Domain\ValueObjects\IntId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProductFilterChangeDTO::class)]
final class ProductFilterChangeDTOTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | filterValuesForDispatch() — non-empty values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function filter_values_for_dispatch_returns_values_as_is_when_multiple_values_present(): void
    {
        $dto = new ProductFilterChangeDTO(IntId::from(1), 15, ['4', '4.5']);

        $this->assertSame(['4', '4.5'], $dto->filterValuesForDispatch());
    }

    #[Test]
    public function filter_values_for_dispatch_returns_values_as_is_when_single_value_present(): void
    {
        $dto = new ProductFilterChangeDTO(IntId::from(1), 15, ['4']);

        $this->assertSame(['4'], $dto->filterValuesForDispatch());
    }

    /*
    |--------------------------------------------------------------------------
    | filterValuesForDispatch() — empty values (removal case)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function filter_values_for_dispatch_returns_null_when_filter_values_are_empty(): void
    {
        $dto = new ProductFilterChangeDTO(IntId::from(1), 15, []);

        $this->assertNull($dto->filterValuesForDispatch());
    }
}
