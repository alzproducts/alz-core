<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Support;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for SqlQueryBuilder utility.
 *
 * Tests SQL escaping and query construction helpers.
 */
#[CoversClass(SqlQueryBuilder::class)]
final class SqlQueryBuilderTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | withIsolationLevel
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_prepends_isolation_level_to_sql(): void
    {
        $sql = 'SELECT * FROM StockItem';

        $result = SqlQueryBuilder::withIsolationLevel($sql);

        $this->assertSame(
            'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; SELECT * FROM StockItem',
            $result,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | escapeString
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('escapeStringProvider')]
    public function it_escapes_strings_for_sql_server(string $input, string $expected): void
    {
        $result = SqlQueryBuilder::escapeString($input);

        $this->assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{input: string, expected: string}>
     */
    public static function escapeStringProvider(): iterable
    {
        yield 'simple string' => [
            'input' => 'ABC123',
            'expected' => "'ABC123'",
        ];

        yield 'empty string' => [
            'input' => '',
            'expected' => "''",
        ];

        yield 'single quote' => [
            'input' => "O'Reilly",
            'expected' => "'O''Reilly'",
        ];

        yield 'multiple single quotes' => [
            'input' => "It's Tom's",
            'expected' => "'It''s Tom''s'",
        ];

        yield 'special characters' => [
            'input' => 'SKU-123_ABC',
            'expected' => "'SKU-123_ABC'",
        ];

        yield 'unicode characters' => [
            'input' => 'Café Möbel',
            'expected' => "'Café Möbel'",
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | buildInClause
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_builds_in_clause_from_string_array(): void
    {
        $values = ['SKU001', 'SKU002', 'SKU003'];

        $result = SqlQueryBuilder::buildInClause($values);

        $this->assertSame("('SKU001', 'SKU002', 'SKU003')", $result);
    }

    #[Test]
    public function it_builds_in_clause_with_single_value(): void
    {
        $values = ['SKU001'];

        $result = SqlQueryBuilder::buildInClause($values);

        $this->assertSame("('SKU001')", $result);
    }

    #[Test]
    public function it_builds_in_clause_escaping_quotes(): void
    {
        $values = ["O'Reilly", 'Normal'];

        $result = SqlQueryBuilder::buildInClause($values);

        $this->assertSame("('O''Reilly', 'Normal')", $result);
    }

    #[Test]
    public function it_throws_on_empty_array_for_in_clause(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN clause values cannot be empty');

        SqlQueryBuilder::buildInClause([]);
    }

    /*
    |--------------------------------------------------------------------------
    | buildGuidInClause
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_builds_in_clause_from_guids(): void
    {
        $guids = [
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
        ];

        $result = SqlQueryBuilder::buildGuidInClause($guids);

        $this->assertSame(
            "('550e8400-e29b-41d4-a716-446655440000', '6ba7b810-9dad-11d1-80b4-00c04fd430c8')",
            $result,
        );
    }

    #[Test]
    public function it_throws_on_empty_guid_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN clause values cannot be empty');

        SqlQueryBuilder::buildGuidInClause([]);
    }
}
