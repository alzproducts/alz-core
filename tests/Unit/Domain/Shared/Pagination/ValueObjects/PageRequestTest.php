<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Pagination\ValueObjects;

use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageRequest::class)]
final class PageRequestTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | from() named constructor
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_creates_with_correct_page_and_per_page_values(): void
    {
        $request = PageRequest::from(3, 50);

        self::assertSame(3, $request->page);
        self::assertSame(50, $request->perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | firstPage() named constructor
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function first_page_defaults_to_page_one_and_per_page_500(): void
    {
        $request = PageRequest::firstPage();

        self::assertSame(1, $request->page);
        self::assertSame(500, $request->perPage);
    }

    #[Test]
    public function first_page_accepts_custom_per_page(): void
    {
        $request = PageRequest::firstPage(100);

        self::assertSame(1, $request->page);
        self::assertSame(100, $request->perPage);
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary: valid edges
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function per_page_of_1000_succeeds_at_max_boundary(): void
    {
        $request = PageRequest::from(1, 1000);

        self::assertSame(1000, $request->perPage);
    }

    #[Test]
    public function per_page_of_1_succeeds_at_minimum(): void
    {
        $request = PageRequest::from(1, 1);

        self::assertSame(1, $request->perPage);
    }

    #[Test]
    public function page_of_1_succeeds_at_minimum(): void
    {
        $request = PageRequest::from(1, 10);

        self::assertSame(1, $request->page);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge: invalid inputs throw InvalidArgumentException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function page_zero_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PageRequest::from(0, 10);
    }

    #[Test]
    public function per_page_zero_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PageRequest::from(1, 0);
    }

    #[Test]
    public function negative_page_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PageRequest::from(-1, 10);
    }

    #[Test]
    public function negative_per_page_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PageRequest::from(1, -1);
    }

    #[Test]
    public function per_page_exceeding_max_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PageRequest::from(1, 1001);
    }
}
