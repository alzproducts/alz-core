<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Support;

use App\Application\Support\CsvParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvParser::class)]
final class CsvParserTest extends TestCase
{
    #[Test]
    public function it_returns_header_stripped_data_rows_with_correct_cells(): void
    {
        [$rows, $error] = CsvParser::rows("name,age\nAlice,30\nBob,25\n", ['name', 'age']);

        self::assertNull($error);
        self::assertCount(2, $rows);
        self::assertSame([2, ['Alice', '30']], $rows[0]);
        self::assertSame([3, ['Bob', '25']], $rows[1]);
    }

    #[Test]
    public function it_rejects_invalid_header(): void
    {
        [$rows, $error] = CsvParser::rows("vendor,item\nAcme,Widget\n", ['name', 'price']);

        self::assertSame([], $rows);
        self::assertSame('Invalid CSV header. Expected: name,price', $error);
    }

    #[Test]
    public function it_normalizes_header_case_and_whitespace(): void
    {
        [$rows, $error] = CsvParser::rows("  Name , AGE \nAlice,30\n", ['name', 'age']);

        self::assertNull($error);
        self::assertCount(1, $rows);
        self::assertSame([2, ['Alice', '30']], $rows[0]);
    }

    #[Test]
    public function it_skips_blank_lines_among_data_rows(): void
    {
        [$rows, $error] = CsvParser::rows("name\nAlice\n\nBob\n", ['name']);

        self::assertNull($error);
        self::assertCount(2, $rows);
        self::assertSame([2, ['Alice']], $rows[0]);
        self::assertSame([4, ['Bob']], $rows[1]);
    }

    #[Test]
    public function it_returns_empty_rows_and_null_error_for_empty_content(): void
    {
        [$rows, $error] = CsvParser::rows('', ['name']);

        self::assertSame([], $rows);
        self::assertNull($error);
    }

    #[Test]
    public function it_returns_empty_rows_and_null_error_for_header_only(): void
    {
        [$rows, $error] = CsvParser::rows("name,age\n", ['name', 'age']);

        self::assertSame([], $rows);
        self::assertNull($error);
    }

    #[Test]
    public function it_assigns_correct_one_based_line_numbers(): void
    {
        [$rows, $error] = CsvParser::rows("col\nA\nB\nC\n", ['col']);

        self::assertNull($error);
        self::assertSame(2, $rows[0][0]);
        self::assertSame(3, $rows[1][0]);
        self::assertSame(4, $rows[2][0]);
    }

    #[Test]
    public function it_preserves_physical_line_numbers_across_skipped_blank_rows(): void
    {
        [$rows, $error] = CsvParser::rows("\ncol\n\nA\n\n", ['col']);

        self::assertNull($error);
        self::assertCount(1, $rows);
        self::assertSame([4, ['A']], $rows[0]);
    }

    #[Test]
    public function it_passes_raw_untrimmed_cell_values_and_coalesces_null_to_empty_string(): void
    {
        [$rows, $error] = CsvParser::rows("a,b\n  hello ,\n", ['a', 'b']);

        self::assertNull($error);
        self::assertSame([2, ['  hello ', '']], $rows[0]);
    }

    #[Test]
    public function it_parses_quoted_fields_containing_commas(): void
    {
        [$rows, $error] = CsvParser::rows("name,value\n\"Smith, Jr.\",42\n", ['name', 'value']);

        self::assertNull($error);
        self::assertSame([2, ['Smith, Jr.', '42']], $rows[0]);
    }
}
