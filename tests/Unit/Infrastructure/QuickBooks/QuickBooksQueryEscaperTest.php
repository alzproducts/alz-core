<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Infrastructure\QuickBooks\QuickBooksQueryEscaper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuickBooksQueryEscaper::class)]
final class QuickBooksQueryEscaperTest extends TestCase
{
    #[Test]
    public function a_literal_escapes_quotes_and_backslashes(): void
    {
        $this->assertSame("'O\\'Brien\\\\x'", QuickBooksQueryEscaper::literal("O'Brien\\x"));
    }

    #[Test]
    public function a_plain_literal_is_only_quoted(): void
    {
        $this->assertSame("'SKU-1'", QuickBooksQueryEscaper::literal('SKU-1'));
    }

    #[Test]
    public function an_in_list_joins_escaped_literals(): void
    {
        $this->assertSame("('A', 'O\\'B')", QuickBooksQueryEscaper::inList(['A', "O'B"]));
    }

    #[Test]
    public function an_in_list_takes_exactly_the_maximum(): void
    {
        $list = QuickBooksQueryEscaper::inList(\array_map(\strval(...), \range(1, QuickBooksQueryEscaper::MAX_IN_VALUES)));

        $this->assertSame(QuickBooksQueryEscaper::MAX_IN_VALUES, \mb_substr_count($list, "'") / 2);
    }

    #[Test]
    public function an_in_list_over_the_maximum_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuickBooksQueryEscaper::inList(\array_map(\strval(...), \range(1, QuickBooksQueryEscaper::MAX_IN_VALUES + 1)));
    }

    #[Test]
    public function chunks_drop_case_repeats_and_split_at_the_maximum(): void
    {
        $values = [...\array_map(static fn(int $i): string => "sku-{$i}", \range(1, 150)), 'SKU-1', '42'];

        $chunks = QuickBooksQueryEscaper::chunks($values);

        $this->assertCount(2, $chunks);
        $this->assertCount(QuickBooksQueryEscaper::MAX_IN_VALUES, $chunks[0]);
        $this->assertSame('sku-1', $chunks[0][0]);
        $this->assertSame(['sku-150', '42'], \array_slice($chunks[1], -2));
        $this->assertCount(51, $chunks[1]);
    }

    #[Test]
    public function no_values_give_no_chunks(): void
    {
        $this->assertSame([], QuickBooksQueryEscaper::chunks([]));
    }
}
