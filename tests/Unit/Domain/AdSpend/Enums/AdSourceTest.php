<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AdSpend\Enums;

use App\Domain\AdSpend\Enums\AdSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdSource Enum Unit Tests.
 *
 * Tests the prefix() and utmSource() methods for all ad source cases.
 * These methods are used for Mixpanel $insert_id generation and UTM tracking.
 */
#[CoversClass(AdSource::class)]
final class AdSourceTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | prefix() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('prefixProvider')]
    public function it_returns_correct_prefix_for_each_source(AdSource $source, string $expectedPrefix): void
    {
        self::assertSame($expectedPrefix, $source->prefix());
    }

    /**
     * @return array<string, array{AdSource, string}>
     */
    public static function prefixProvider(): array
    {
        return [
            'Google returns G' => [AdSource::Google, 'G'],
            'Bing returns B' => [AdSource::Bing, 'B'],
            'Facebook returns F' => [AdSource::Facebook, 'F'],
        ];
    }

    #[Test]
    public function prefix_returns_first_character_of_value(): void
    {
        // Verify the implementation pattern: prefix is always the first character
        foreach (AdSource::cases() as $source) {
            self::assertSame(
                $source->value[0],
                $source->prefix(),
                "Prefix for {$source->name} should be first character of '{$source->value}'",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | utmSource() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('utmSourceProvider')]
    public function it_returns_correct_utm_source_for_each_source(AdSource $source, string $expectedUtmSource): void
    {
        self::assertSame($expectedUtmSource, $source->utmSource());
    }

    /**
     * @return array<string, array{AdSource, string}>
     */
    public static function utmSourceProvider(): array
    {
        return [
            'Google returns google' => [AdSource::Google, 'google'],
            'Bing returns bing' => [AdSource::Bing, 'bing'],
            'Facebook returns facebook' => [AdSource::Facebook, 'facebook'],
        ];
    }

    #[Test]
    public function utm_source_returns_lowercase_value(): void
    {
        // Verify the implementation pattern: utmSource is always lowercase value
        foreach (AdSource::cases() as $source) {
            self::assertSame(
                \mb_strtolower($source->value),
                $source->utmSource(),
                "UTM source for {$source->name} should be lowercase of '{$source->value}'",
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Value Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enum_has_exactly_three_cases(): void
    {
        self::assertCount(3, AdSource::cases());
    }

    #[Test]
    public function enum_values_are_pascal_case(): void
    {
        self::assertSame('Google', AdSource::Google->value);
        self::assertSame('Bing', AdSource::Bing->value);
        self::assertSame('Facebook', AdSource::Facebook->value);
    }
}
