<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Presentation\Http\Api\DTOs\ListProductsRequestDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ListProductsRequestDTO::class)]
final class ListProductsRequestDTOTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | validatedIncludes()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function validated_includes_returns_empty_array_when_include_is_null(): void
    {
        $dto = new ListProductsRequestDTO(include: null);

        $this->assertSame([], $dto->validatedIncludes());
    }

    #[Test]
    public function validated_includes_returns_empty_array_when_include_is_empty_string(): void
    {
        $dto = new ListProductsRequestDTO(include: '');

        $this->assertSame([], $dto->validatedIncludes());
    }

    #[Test]
    public function validated_includes_returns_variations_for_variations_string(): void
    {
        $dto = new ListProductsRequestDTO(include: 'variations');

        $this->assertSame(['variations'], $dto->validatedIncludes());
    }

    #[Test]
    public function validated_includes_trims_whitespace_from_include_values(): void
    {
        $dto = new ListProductsRequestDTO(include: ' variations ');

        $this->assertSame(['variations'], $dto->validatedIncludes());
    }

    /*
    |--------------------------------------------------------------------------
    | allowedIncludes()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function allowed_includes_returns_variations_inventory_and_stock(): void
    {
        $this->assertSame(['variations', 'inventory', 'stock'], ListProductsRequestDTO::allowedIncludes());
    }

    /*
    |--------------------------------------------------------------------------
    | Default values
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function defaults_are_per_page_500_page_1_include_null(): void
    {
        $dto = new ListProductsRequestDTO();

        $this->assertSame(500, $dto->per_page);
        $this->assertSame(1, $dto->page);
        $this->assertNull($dto->include);
    }
}
