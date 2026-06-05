<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Console\Parsers;

use App\Presentation\Console\Parsers\CostPriceCsvParser;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CostPriceCsvParser::class)]
final class CostPriceCsvParserTest extends TestCase
{
    private CostPriceCsvParser $parser;

    /** @var list<string> */
    private array $tempFiles = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CostPriceCsvParser();
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    #[Test]
    public function it_groups_valid_rows_by_supplier(): void
    {
        $file = $this->makeCsv("supplier,sku,cost_price\nAcmeCo,SKU-001,10.50\nAcmeCo,SKU-002,20.00\nGlobalParts,SKU-003,5.25\n");

        [$batches, $errors] = $this->parser->parse($file);

        self::assertSame([], $errors);
        self::assertCount(2, $batches);
        self::assertSame('AcmeCo', $batches[0]->supplierName);
        self::assertCount(2, $batches[0]->commands);
        self::assertSame('SKU-001', $batches[0]->commands[0]->sku->value);
        self::assertSame(10.50, $batches[0]->commands[0]->costPrice->toNet());
        self::assertSame('GlobalParts', $batches[1]->supplierName);
        self::assertCount(1, $batches[1]->commands);
    }

    #[Test]
    public function it_accepts_zero_cost(): void
    {
        $file = $this->makeCsv("supplier,sku,cost_price\nAcmeCo,SKU-001,0\n");

        [$batches, $errors] = $this->parser->parse($file);

        self::assertSame([], $errors);
        self::assertSame(0.0, $batches[0]->commands[0]->costPrice->toNet());
    }

    #[Test]
    public function it_collects_all_bad_rows_with_line_numbers_and_returns_no_batches(): void
    {
        $file = $this->makeCsv("supplier,sku,cost_price\n,SKU-001,10.50\nAcmeCo,SKU-002,-5\nAcmeCo,SKU-003,abc\nAcmeCo,,3.00\n");

        [$batches, $errors] = $this->parser->parse($file);

        self::assertSame([], $batches);
        self::assertCount(4, $errors);
        self::assertSame('Row 2: supplier is empty', $errors[0]);
        self::assertSame("Row 3: cost_price must be a number >= 0, got '-5'", $errors[1]);
        self::assertSame("Row 4: cost_price must be a number >= 0, got 'abc'", $errors[2]);
        self::assertStringContainsString('Row 5', $errors[3]);
    }

    #[Test]
    public function it_rejects_an_invalid_header(): void
    {
        $file = $this->makeCsv("vendor,item,price\nAcmeCo,SKU-001,10.50\n");

        [$batches, $errors] = $this->parser->parse($file);

        self::assertSame([], $batches);
        self::assertCount(1, $errors);
        self::assertStringContainsString('Invalid CSV header', $errors[0]);
    }

    #[Test]
    public function it_skips_blank_lines_and_takes_the_first_non_blank_row_as_header(): void
    {
        $file = $this->makeCsv("\nsupplier,sku,cost_price\n\nAcmeCo,SKU-001,10.50\n\n");

        [$batches, $errors] = $this->parser->parse($file);

        self::assertSame([], $errors);
        self::assertCount(1, $batches);
        self::assertCount(1, $batches[0]->commands);
    }

    private function makeCsv(string $content): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'cor192-csv');
        if ($file === false) {
            self::fail('Could not create temp CSV file');
        }
        \file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return $file;
    }
}
