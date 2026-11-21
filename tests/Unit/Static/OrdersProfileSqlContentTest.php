<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\OrdersProfileSqlCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * The orders profile export SQL is one read-only SELECT that returns no PII, references or order dates (COR-297 AC1, AC2).
 */
#[CoversNothing]
#[Group('repo-static')]
final class OrdersProfileSqlContentTest extends TestCase
{
    private const string ANCHOR = "(SELECT count(1) FROM dedup WHERE lifecycle_status = 'cancelled') AS cancelled,";

    #[Test]
    public function the_committed_export_sql_passes_the_check(): void
    {
        $this->assertSame([], OrdersProfileSqlCheck::violations(self::committedSql()));
    }

    /**
     * A real orders or order-line column for each banned word.
     *
     * @return iterable<string, array{string}>
     */
    public static function bannedColumns(): iterable
    {
        $columns = [
            'name' => 'billing_name', 'email' => 'delivery_email', 'telephone' => 'billing_telephone',
            'company' => 'delivery_company', 'address' => 'billing_address_line1', 'city' => 'delivery_city',
            'province' => 'billing_province', 'state' => 'delivery_state', 'postcode' => 'delivery_postcode',
            'reference' => 'reference', 'external_id' => 'external_id', 'customer_id' => 'customer_id',
            'birth' => 'customer_date_of_birth', 'device' => 'customer_device_info', 'transaction' => 'transaction_id',
            'url' => 'tracking_url', 'comments' => 'comments', 'custom_fields' => 'custom_fields',
            'variation' => 'variation_hash', 'title' => 'title', 'sku' => 'sku', 'cost' => 'cost_price',
        ];

        foreach (OrdersProfileSqlCheck::BANNED_WORDS as $word) {
            yield $word => [$columns[$word]];
        }
    }

    #[Test]
    #[DataProvider('bannedColumns')]
    public function a_planted_banned_column_fails_the_check(string $column): void
    {
        $this->assertSame(
            ['SELECT list names a banned column outside count(): o.' . $column],
            OrdersProfileSqlCheck::violations(self::planted("(SELECT max(o.{$column}) FROM shopwired.orders o) AS cancelled,")),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function plantedSql(): iterable
    {
        yield 'a raw order date' => [
            '(SELECT o.order_placed_at FROM shopwired.orders o LIMIT 1) AS cancelled,',
            ['SELECT list names an order date outside an aggregate: o.order_placed_at'],
        ];
        yield 'a raw delivery date' => [
            '(SELECT jsonb_agg(o.delivery_date) FROM shopwired.orders o) AS cancelled,',
            ['SELECT list names an order date outside an aggregate: o.delivery_date'],
        ];
        yield 'a whole row as jsonb' => [
            '(SELECT jsonb_agg(to_jsonb(o)) FROM shopwired.orders o) AS cancelled,',
            ['SELECT list holds a row expansion: to_jsonb'],
        ];
        yield 'a star' => [
            '(SELECT jsonb_agg(x) FROM (SELECT * FROM shopwired.orders) x) AS cancelled,',
            ['SELECT list holds a row expansion: *'],
        ];
        yield 'an INSERT' => [
            '(SELECT 1) AS cancelled); INSERT INTO shopwired.orders DEFAULT VALUES; SELECT (1,',
            [
                'orders profile SQL holds a second statement',
                'orders profile SQL holds a forbidden keyword: insert',
                'orders profile SQL holds a second statement',
            ],
        ];
        yield 'a psql meta-command' => ['(SELECT 1) AS cancelled, \\! id', ['orders profile SQL holds a psql meta-command']];
        yield 'an aggregated order date and counted references' => [
            "(SELECT jsonb_build_object('first', min(o.order_placed_at), 'refs', count(DISTINCT o.reference)) FROM shopwired.orders o) AS cancelled,",
            [],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('plantedSql')]
    public function a_planted_export_query_is_judged(string $replace, array $expected): void
    {
        $this->assertSame($expected, OrdersProfileSqlCheck::violations(self::planted($replace)));
    }

    #[Test]
    public function banned_words_in_comments_strings_and_predicates_pass(): void
    {
        $sql = "-- billing_email\nSELECT jsonb_build_object('reference', (SELECT count(DISTINCT o.reference) FROM shopwired.orders o WHERE o.billing_email <> '' GROUP BY o.sku ORDER BY o.order_placed_at))";

        $this->assertSame([], OrdersProfileSqlCheck::violations($sql));
    }

    #[Test]
    public function a_missing_or_empty_file_fails_closed(): void
    {
        $this->assertSame(['orders profile SQL is missing'], OrdersProfileSqlCheck::violations(null));
        $this->assertSame(['orders profile SQL has no SELECT'], OrdersProfileSqlCheck::violations(''));
    }

    private static function planted(string $replace): string
    {
        $sql = self::committedSql();
        self::assertIsString($sql);
        self::assertSame(1, \mb_substr_count($sql, self::ANCHOR));

        return \str_replace(self::ANCHOR, $replace, $sql);
    }

    private static function committedSql(): ?string
    {
        $root = \dirname(__DIR__, 3);

        return \in_array(OrdersProfileSqlCheck::PATH, TrackedFiles::list($root, OrdersProfileSqlCheck::PATH), true)
            ? TrackedFiles::reader($root)(OrdersProfileSqlCheck::PATH)
            : null;
    }
}
