<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\TrackedFiles;
use Tests\Unit\Static\Support\VatReplaySqlCheck;

/**
 * The VAT replay SQL is one read-only SELECT whose output holds no PII, order identifiers, prices, costs or margins (AC7).
 */
#[CoversNothing]
#[Group('repo-static')]
final class VatReplaySqlContentTest extends TestCase
{
    #[Test]
    public function the_committed_replay_sql_passes_the_check(): void
    {
        $this->assertSame([], VatReplaySqlCheck::violations(self::committedSql()));
    }

    #[Test]
    public function every_reviewed_cte_carries_a_computation_prefix(): void
    {
        foreach (VatReplaySqlCheck::REVIEWED_CTES as $name) {
            $this->assertMatchesRegularExpression('/^(src|m1|zap)_[a-z0-9_]+$/', $name);
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function plantedSql(): iterable
    {
        $baseline = "'baseline', (SELECT count(1) FILTER (WHERE base) FROM m1_results),";
        $deltas = 'jsonb_object_agg(delta_pence::text, n) AS deltas';

        yield 'an INSERT' => [$baseline, "'baseline', (SELECT count(1) FROM m1_results)); INSERT INTO shopwired.orders DEFAULT VALUES; SELECT (1,", [
            'replay SQL holds a second statement',
            'replay SQL holds a forbidden keyword: insert',
            'replay SQL holds a second statement',
        ]];
        yield 'a reference in an output list' => [$baseline, "'baseline', (SELECT max(reference) FROM shopwired.orders),", ['output select list names a banned identifier: reference']];
        yield 'references as aggregate keys' => [$deltas, 'jsonb_object_agg(reference, n) AS deltas', ['output select list names a banned identifier: reference']];
        yield 'external ids as aggregate keys' => [$deltas, 'jsonb_object_agg(external_id, n) AS deltas', ['output select list names a banned identifier: external_id']];
        yield 'a star in an output list' => [$baseline, "'baseline', (SELECT count(*) FROM m1_results),", ['output select list holds a row expansion: *']];
        yield 'a whole row as jsonb' => [$baseline, "'baseline', (SELECT to_jsonb(r) FROM m1_results r LIMIT 1),", ['output select list holds a row expansion: to_jsonb']];
        yield 'a setval call' => [$baseline, "'baseline', (SELECT setval('shopwired.orders_id_seq', 1)),", ['replay SQL calls a forbidden function: setval']];
        yield 'a missing zap_as_built key' => [
            "'zap_as_built', (SELECT jsonb_object_agg(mode, body) FROM out_modes WHERE variant = 'zap_as_built')",
            "'zap', (SELECT jsonb_object_agg(mode, body) FROM out_modes WHERE variant = 'zap_as_built')",
            ['replay output lacks the key: zap_as_built'],
        ];
        yield 'a round() inside a zap_ CTE' => [
            'floor(n.net7 * l.qty * 100 + sign(n.net7 * l.qty) * 0.0001::float8 + 0.5::float8) / 100 AS amount',
            'round((n.net7 * l.qty)::numeric, 2) AS amount',
            ['zap_ CTE calls round(): zap_lines'],
        ];
        yield 'a psql meta-command' => [$baseline, "'baseline', (SELECT 1), \\! id", ['replay SQL holds a psql meta-command']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('plantedSql')]
    public function a_planted_replay_query_fails_the_check(string $search, string $replace, array $expected): void
    {
        $sql = self::committedSql();
        $this->assertIsString($sql);
        $this->assertSame(1, \mb_substr_count($sql, $search));

        $this->assertSame($expected, VatReplaySqlCheck::violations(\str_replace($search, $replace, $sql)));
    }

    #[Test]
    public function a_missing_or_empty_file_fails_closed(): void
    {
        $this->assertSame(['replay SQL is missing'], VatReplaySqlCheck::violations(null));
        $this->assertSame(['replay SQL has no SELECT'], VatReplaySqlCheck::violations(''));
    }

    private static function committedSql(): ?string
    {
        $root = \dirname(__DIR__, 3);

        return \in_array(VatReplaySqlCheck::PATH, TrackedFiles::list($root, VatReplaySqlCheck::PATH), true)
            ? TrackedFiles::reader($root)(VatReplaySqlCheck::PATH)
            : null;
    }
}
