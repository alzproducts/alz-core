<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Static\Support\ProfileContentCheck;
use Tests\Unit\Static\Support\ProfileJsonCheck;
use Tests\Unit\Static\Support\ProfileSqlCheck;
use Tests\Unit\Static\Support\TrackedFiles;

/**
 * The committed seed profile and its export SQL hold only counts and structural distributions (AC11).
 */
#[CoversNothing]
#[Group('repo-static')]
final class SeedProfileContentTest extends TestCase
{
    #[Test]
    public function the_committed_profile_files_hold_only_counts_and_distributions(): void
    {
        $root = \base_path();

        $this->assertSame([], ProfileContentCheck::violations(TrackedFiles::list($root, 'database/seed-profile'), TrackedFiles::reader($root)));
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, list<string>}>
     */
    public static function plantedProfiles(): iterable
    {
        yield 'a price figure' => [
            static fn(array $profile): array => self::with($profile, 'products', 'avg_price', 12.34),
            [
                'key path not in the allowlist: products.avg_price',
            ],
        ];
        yield 'a price figure on an allowlisted count' => [
            static fn(array $profile): array => self::with($profile, 'products', 'price_zero', 0.5),
            ['key path naming a banned word holds something other than a count: products.price_zero'],
        ];
        yield 'product titles as distribution keys' => [
            static fn(array $profile): array => self::with($profile, 'products', 'top_titles', ['Deluxe Widget' => 3]),
            ['key path not in the allowlist: products.top_titles'],
        ];
        yield 'a supplier name as a currency bucket' => [
            static fn(array $profile): array => self::with($profile, 'stock_item_suppliers', 'supplier_currency', ['Acme Ltd' => 4]),
            ['key path not in the allowlist: stock_item_suppliers.supplier_currency.Acme Ltd'],
        ];
        yield 'a string value in a custom field histogram' => [
            static fn(array $profile): array => self::with($profile, 'categories', 'custom_field_keys', ['custom_h1' => 'Best Widgets']),
            ['expected int at categories.custom_field_keys.custom_h1'],
        ];
        yield 'a dropped count' => [
            static function (array $profile): array {
                unset($profile['counts']['products']);

                return $profile;
            },
            ['missing key path counts.products'],
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $plant
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('plantedProfiles')]
    public function a_planted_profile_fails_the_check(callable $plant, array $expected): void
    {
        $json = \json_encode($plant(self::committedProfile()), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);

        $this->assertSame($expected, ProfileJsonCheck::violations($json));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function plantedSql(): iterable
    {
        $rrp = "'rrp_null', (SELECT count(*) FROM catalog.product_extra_data WHERE rrp IS NULL),";

        yield 'a selected price' => [$rrp, "'max_price', (SELECT max(price) FROM p),", ['SELECT list names a banned column outside count(): price']];
        yield 'aggregated titles' => [$rrp, "'titles', (SELECT jsonb_agg(p.title) FROM p),", ['SELECT list names a banned column outside count(): p.title']];
        yield 'a summed cost' => [$rrp, "'cost', (SELECT sum(cost_price) FROM p WHERE cost_price > 0),", ['SELECT list names a banned column outside count(): cost_price']];
        yield 'supplier names as keys' => [
            $rrp,
            "'by_supplier', (SELECT jsonb_object_agg(supplier_name, n) FROM (SELECT supplier_name, count(*) AS n FROM linnworks.suppliers GROUP BY 1) h),",
            [
                'SELECT list names a banned column outside count(): supplier_name',
                'SELECT list names a banned column outside count(): supplier_name',
            ],
        ];
        yield 'an edited exempt query' => [
            'SELECT sku FROM p WHERE sku IS NOT NULL',
            'SELECT sku, title FROM p WHERE sku IS NOT NULL',
            [
                'SELECT list names a banned column outside count(): sku',
                'SELECT list names a banned column outside count(): title',
                'SELECT list names a banned column outside count(): sku',
            ],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('plantedSql')]
    public function a_planted_export_query_fails_the_check(string $search, string $replace, array $expected): void
    {
        $sql = self::committedFile(ProfileContentCheck::EXPORT_SQL);
        $this->assertStringContainsString($search, $sql);

        $this->assertSame($expected, ProfileSqlCheck::violations(\str_replace($search, $replace, $sql)));
    }

    #[Test]
    public function banned_words_in_comments_strings_and_predicates_pass(): void
    {
        $sql = "-- no prices here\nSELECT jsonb_build_object('price_zero', (SELECT count(*) FROM p WHERE price = 0 GROUP BY sku ORDER BY title))";

        $this->assertSame([], ProfileSqlCheck::violations($sql));
    }

    #[Test]
    public function an_empty_listing_fails_closed(): void
    {
        $this->assertSame(
            [
                'profile file missing from the listing: ' . ProfileContentCheck::PROFILE_JSON,
                'profile file missing from the listing: ' . ProfileContentCheck::EXPORT_SQL,
            ],
            ProfileContentCheck::violations([], static fn(string $path): string => ''),
        );
    }

    #[Test]
    public function a_listing_missing_one_profile_file_fails_closed(): void
    {
        $root = \base_path();

        $this->assertSame(
            ['profile file missing from the listing: ' . ProfileContentCheck::EXPORT_SQL],
            ProfileContentCheck::violations([ProfileContentCheck::PROFILE_JSON], TrackedFiles::reader($root)),
        );
    }

    #[Test]
    public function empty_profile_files_fail_closed(): void
    {
        $this->assertSame(['profile is not a non-empty JSON object'], ProfileJsonCheck::violations('{}'));
        $this->assertSame(['profile is not valid JSON: Syntax error'], ProfileJsonCheck::violations(''));
        $this->assertSame(['export SQL has no SELECT'], ProfileSqlCheck::violations(''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function committedProfile(): array
    {
        /** @var array<string, mixed> */
        return \json_decode(self::committedFile(ProfileContentCheck::PROFILE_JSON), true, 32, \JSON_THROW_ON_ERROR);
    }

    private static function committedFile(string $path): string
    {
        return TrackedFiles::reader(\dirname(__DIR__, 3))($path);
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function with(array $profile, string $section, string $key, mixed $value): array
    {
        /** @var array<string, mixed> $node */
        $node = $profile[$section];
        $node[$key] = $value;
        $profile[$section] = $node;

        return $profile;
    }
}
