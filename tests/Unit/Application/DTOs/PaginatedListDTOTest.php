<?php

declare(strict_types=1);

namespace Tests\Unit\Application\DTOs;

use App\Application\DTOs\PaginatedListDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PaginatedListDTO::class)]
final class PaginatedListDTOTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromPage() — lastPage computation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_page_computes_last_page_with_exact_division(): void
    {
        $dto = PaginatedListDTO::fromPage(items: [], total: 100, perPage: 10, currentPage: 1);

        $this->assertSame(10, $dto->lastPage);
    }

    #[Test]
    public function from_page_rounds_up_last_page_when_remainder_exists(): void
    {
        $dto = PaginatedListDTO::fromPage(items: [], total: 101, perPage: 10, currentPage: 1);

        $this->assertSame(11, $dto->lastPage);
    }

    #[Test]
    public function from_page_returns_zero_last_page_when_total_is_zero(): void
    {
        $dto = PaginatedListDTO::fromPage(items: [], total: 0, perPage: 10, currentPage: 1);

        $this->assertSame(0, $dto->lastPage);
    }

    #[Test]
    public function from_page_returns_one_last_page_for_single_item(): void
    {
        $dto = PaginatedListDTO::fromPage(items: ['item'], total: 1, perPage: 10, currentPage: 1);

        $this->assertSame(1, $dto->lastPage);
    }

    #[Test]
    public function from_page_uses_max_guard_to_prevent_division_by_zero_when_per_page_is_zero(): void
    {
        // max(1, 0) = 1, so lastPage = ceil(5 / 1) = 5
        $dto = PaginatedListDTO::fromPage(items: [], total: 5, perPage: 0, currentPage: 1);

        $this->assertSame(5, $dto->lastPage);
    }

    /*
    |--------------------------------------------------------------------------
    | fromPage() — property preservation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_page_preserves_all_properties(): void
    {
        $items = ['a', 'b', 'c'];

        $dto = PaginatedListDTO::fromPage(items: $items, total: 30, perPage: 10, currentPage: 2);

        $this->assertSame($items, $dto->items);
        $this->assertSame(30, $dto->total);
        $this->assertSame(10, $dto->perPage);
        $this->assertSame(2, $dto->currentPage);
        $this->assertSame(3, $dto->lastPage);
    }

    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function constructor_creates_instance_with_all_properties_accessible(): void
    {
        $items = [1, 2];

        $dto = new PaginatedListDTO(
            items: $items,
            total: 20,
            perPage: 5,
            currentPage: 3,
            lastPage: 4,
        );

        $this->assertSame($items, $dto->items);
        $this->assertSame(20, $dto->total);
        $this->assertSame(5, $dto->perPage);
        $this->assertSame(3, $dto->currentPage);
        $this->assertSame(4, $dto->lastPage);
    }
}
