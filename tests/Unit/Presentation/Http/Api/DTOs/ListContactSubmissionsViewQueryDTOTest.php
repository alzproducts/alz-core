<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Presentation\Http\Api\DTOs\ListContactSubmissionsViewQueryDTO;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ListContactSubmissionsViewQueryDTO::class)]
final class ListContactSubmissionsViewQueryDTOTest extends TestCase
{
    #[Test]
    public function defaults_produce_page_one_of_fifty(): void
    {
        $dto = new ListContactSubmissionsViewQueryDTO();

        self::assertSame(1, $dto->page);
        self::assertSame(50, $dto->per_page);
    }

    #[Test]
    public function per_page_above_max_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        ListContactSubmissionsViewQueryDTO::validate(['per_page' => 1001]);
    }

    #[Test]
    public function page_below_one_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        ListContactSubmissionsViewQueryDTO::validate(['page' => 0]);
    }

    #[Test]
    public function explicit_pagination_is_preserved(): void
    {
        $dto = new ListContactSubmissionsViewQueryDTO(per_page: 25, page: 3);

        self::assertSame(3, $dto->page);
        self::assertSame(25, $dto->per_page);
    }
}
