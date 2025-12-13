<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Infrastructure\HelpScout\Responses\PageResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PageResponse::class)]
final class PageResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response(): void
    {
        $apiResponse = [
            'size' => 25,
            'totalElements' => 150,
            'totalPages' => 6,
            'number' => 1,
        ];

        $pageResponse = PageResponse::from($apiResponse);

        $this->assertSame(25, $pageResponse->size);
        $this->assertSame(150, $pageResponse->totalElements);
        $this->assertSame(6, $pageResponse->totalPages);
        $this->assertSame(1, $pageResponse->number);
    }

    #[Test]
    public function it_parses_single_page_response(): void
    {
        $apiResponse = [
            'size' => 10,
            'totalElements' => 5,
            'totalPages' => 1,
            'number' => 1,
        ];

        $pageResponse = PageResponse::from($apiResponse);

        $this->assertSame(10, $pageResponse->size);
        $this->assertSame(5, $pageResponse->totalElements);
        $this->assertSame(1, $pageResponse->totalPages);
        $this->assertSame(1, $pageResponse->number);
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination Logic Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_more_pages_returns_true_when_not_on_last_page(): void
    {
        $pageResponse = PageResponse::from([
            'size' => 25,
            'totalElements' => 75,
            'totalPages' => 3,
            'number' => 1,
        ]);

        $this->assertTrue($pageResponse->hasMorePages());
    }

    #[Test]
    public function has_more_pages_returns_true_on_middle_page(): void
    {
        $pageResponse = PageResponse::from([
            'size' => 25,
            'totalElements' => 75,
            'totalPages' => 3,
            'number' => 2,
        ]);

        $this->assertTrue($pageResponse->hasMorePages());
    }

    #[Test]
    public function has_more_pages_returns_false_on_last_page(): void
    {
        $pageResponse = PageResponse::from([
            'size' => 25,
            'totalElements' => 75,
            'totalPages' => 3,
            'number' => 3,
        ]);

        $this->assertFalse($pageResponse->hasMorePages());
    }

    #[Test]
    public function has_more_pages_returns_false_when_only_one_page(): void
    {
        $pageResponse = PageResponse::from([
            'size' => 25,
            'totalElements' => 10,
            'totalPages' => 1,
            'number' => 1,
        ]);

        $this->assertFalse($pageResponse->hasMorePages());
    }

    #[Test]
    public function has_more_pages_returns_false_when_zero_pages(): void
    {
        $pageResponse = PageResponse::from([
            'size' => 25,
            'totalElements' => 0,
            'totalPages' => 0,
            'number' => 0,
        ]);

        $this->assertFalse($pageResponse->hasMorePages());
    }
}
