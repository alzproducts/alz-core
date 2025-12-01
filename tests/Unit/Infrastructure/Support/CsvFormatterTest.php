<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Support;

use App\Infrastructure\Support\CsvFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvFormatter::class)]
final class CsvFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_simple_headers_and_rows(): void
    {
        $headers = ['name', 'email', 'age'];
        $rows = [
            ['John Doe', 'john@example.com', '30'],
            ['Jane Smith', 'jane@example.com', '28'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        $expected = "name,email,age\r\nJohn Doe,john@example.com,30\r\nJane Smith,jane@example.com,28\r\n";
        self::assertSame($expected, $csv);
    }

    #[Test]
    public function it_escapes_values_with_commas(): void
    {
        $headers = ['address'];
        $rows = [
            ['123 Main St, Apt 4'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("address\r\n\"123 Main St, Apt 4\"\r\n", $csv);
    }

    #[Test]
    public function it_escapes_values_with_double_quotes(): void
    {
        $headers = ['title'];
        $rows = [
            ['Software "Engineer"'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("title\r\n\"Software \"\"Engineer\"\"\"\r\n", $csv);
    }

    #[Test]
    public function it_escapes_values_with_newlines(): void
    {
        $headers = ['description'];
        $rows = [
            ["Line 1\nLine 2"],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("description\r\n\"Line 1\nLine 2\"\r\n", $csv);
    }

    #[Test]
    public function it_escapes_values_with_multiple_special_characters(): void
    {
        $headers = ['data'];
        $rows = [
            ['Value with "quotes", commas, and' . "\n" . 'newlines'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertStringContainsString('"Value with ""quotes"", commas, and' . "\n" . 'newlines"', $csv);
    }

    #[Test]
    public function it_preserves_whitespace(): void
    {
        $headers = ['text'];
        $rows = [
            ['  leading and trailing spaces  '],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertStringContainsString('  leading and trailing spaces  ', $csv);
    }

    #[Test]
    public function it_does_not_quote_simple_values(): void
    {
        $headers = ['name', 'age'];
        $rows = [
            ['John', '30'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("name,age\r\nJohn,30\r\n", $csv);
    }

    #[Test]
    public function it_handles_empty_rows_array(): void
    {
        $headers = ['name', 'email'];
        $rows = [];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("name,email\r\n", $csv);
    }

    #[Test]
    public function it_ends_with_crlf(): void
    {
        $headers = ['name'];
        $rows = [['John']];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertTrue(\str_ends_with($csv, "\r\n"), 'CSV must end with CRLF');
    }

    #[Test]
    public function it_uses_crlf_between_all_rows(): void
    {
        $headers = ['id', 'name'];
        $rows = [
            ['1', 'Alice'],
            ['2', 'Bob'],
            ['3', 'Charlie'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        $lines = \explode("\r\n", \mb_rtrim($csv, "\r\n"));
        self::assertCount(4, $lines);
        self::assertSame('id,name', $lines[0]);
        self::assertSame('1,Alice', $lines[1]);
        self::assertSame('2,Bob', $lines[2]);
        self::assertSame('3,Charlie', $lines[3]);
    }

    #[Test]
    public function it_escapes_headers_with_special_characters(): void
    {
        $headers = ['Product, Name', 'Price "USD"'];
        $rows = [
            ['Widget', '99.99'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertStringContainsString('"Product, Name"', $csv);
        self::assertStringContainsString('"Price ""USD"""', $csv);
    }

    #[Test]
    public function it_handles_single_row_single_column(): void
    {
        $headers = ['value'];
        $rows = [['test']];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("value\r\ntest\r\n", $csv);
    }

    #[Test]
    public function it_handles_empty_string_values(): void
    {
        $headers = ['first', 'middle', 'last'];
        $rows = [
            ['John', '', 'Doe'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("first,middle,last\r\nJohn,,Doe\r\n", $csv);
    }

    #[Test]
    public function it_escapes_value_with_comma_only(): void
    {
        $escaped = CsvFormatter::escapeValue('has,comma');
        self::assertSame('"has,comma"', $escaped);
    }

    #[Test]
    public function it_escapes_value_with_quote_only(): void
    {
        $escaped = CsvFormatter::escapeValue('has"quote');
        self::assertSame('"has""quote"', $escaped);
    }

    #[Test]
    public function it_escapes_value_with_newline_only(): void
    {
        $escaped = CsvFormatter::escapeValue("has\nnewline");
        self::assertSame('"has' . "\n" . 'newline"', $escaped);
    }

    #[Test]
    public function it_does_not_escape_simple_value(): void
    {
        $escaped = CsvFormatter::escapeValue('simple');
        self::assertSame('simple', $escaped);
    }

    #[Test]
    public function it_escapes_quote_before_wrapping(): void
    {
        // Critical: double-quotes must be escaped BEFORE wrapping in quotes
        // If quote escaping happens after wrapping, result would be wrong
        $escaped = CsvFormatter::escapeValue('test"value,with,comma');

        // Should be: escape quote to "", then wrap in quotes
        // Result: "test""value,with,comma"
        self::assertSame('"test""value,with,comma"', $escaped);
    }

    #[Test]
    public function it_handles_consecutive_quotes(): void
    {
        $escaped = CsvFormatter::escapeValue('two""quotes');
        self::assertSame('"two""""quotes"', $escaped);
    }

    #[Test]
    public function it_formats_csv_with_numeric_strings(): void
    {
        $headers = ['id', 'amount', 'rate'];
        $rows = [
            ['123', '99.99', '0.05'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        self::assertSame("id,amount,rate\r\n123,99.99,0.05\r\n", $csv);
    }

    #[Test]
    public function it_handles_multiple_rows_with_mixed_escaping(): void
    {
        $headers = ['name', 'description'];
        $rows = [
            ['Simple', 'No special chars'],
            ['With, comma', 'Has comma in field'],
            ['With "quotes"', 'Has quotes in field'],
            ['Normal', 'All normal'],
        ];

        $csv = CsvFormatter::format($headers, $rows);

        $lines = \explode("\r\n", \mb_rtrim($csv, "\r\n"));
        self::assertCount(5, $lines);
        self::assertSame('name,description', $lines[0]);
        self::assertSame('Simple,No special chars', $lines[1]);
        self::assertSame('"With, comma",Has comma in field', $lines[2]);
        self::assertSame('"With ""quotes""",Has quotes in field', $lines[3]);
        self::assertSame('Normal,All normal', $lines[4]);
    }
}
