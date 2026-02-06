<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\Product\Resolvers\VariationOptionMatcher;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VariationOptionMatcher domain service.
 *
 * Tests the normalized option matching algorithm: case-insensitive,
 * order-independent comparison of option_name:value_name pairs.
 */
#[CoversClass(VariationOptionMatcher::class)]
final class VariationOptionMatcherTest extends TestCase
{
    private VariationOptionMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new VariationOptionMatcher();
    }

    /*
    |--------------------------------------------------------------------------
    | Exact Match Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_matches_variation_with_identical_options(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Size', 'Large'),
            self::option('Color', 'Red'),
        ]);

        $candidates = [
            self::createVariation(id: 10, options: [
                self::option('Size', 'Large'),
                self::option('Color', 'Red'),
            ]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNotNull($result);
        self::assertSame(10, $result->id);
    }

    #[Test]
    public function it_matches_single_option_variation(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Color', 'Blue'),
        ]);

        $candidates = [
            self::createVariation(id: 10, options: [self::option('Color', 'Red')]),
            self::createVariation(id: 11, options: [self::option('Color', 'Blue')]),
            self::createVariation(id: 12, options: [self::option('Color', 'Green')]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNotNull($result);
        self::assertSame(11, $result->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Case Insensitive Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_matches_case_insensitively(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('COLOR', 'RED'),
        ]);

        $candidates = [
            self::createVariation(id: 10, options: [
                self::option('color', 'red'),
            ]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNotNull($result);
        self::assertSame(10, $result->id);
    }

    #[Test]
    public function it_matches_mixed_case(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Colour', 'Dark Blue'),
        ]);

        $candidates = [
            self::createVariation(id: 10, options: [
                self::option('colour', 'dark blue'),
            ]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNotNull($result);
        self::assertSame(10, $result->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Option Order Independence Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_matches_regardless_of_option_order(): void
    {
        // Target has Color then Size
        $target = self::createVariation(id: 1, options: [
            self::option('Color', 'Red'),
            self::option('Size', 'Large'),
        ]);

        // Candidate has Size then Color (reversed order)
        $candidates = [
            self::createVariation(id: 10, options: [
                self::option('Size', 'Large'),
                self::option('Color', 'Red'),
            ]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNotNull($result);
        self::assertSame(10, $result->id);
    }

    #[Test]
    public function it_matches_with_three_options_in_different_order(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Size', '300mm'),
            self::option('Material', 'Aluminium'),
            self::option('Fixing', 'Self-Adhesive'),
        ]);

        $candidates = [
            self::createVariation(id: 10, options: [
                self::option('Fixing', 'Self-Adhesive'),
                self::option('Size', '300mm'),
                self::option('Material', 'Aluminium'),
            ]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNotNull($result);
        self::assertSame(10, $result->id);
    }

    /*
    |--------------------------------------------------------------------------
    | No Match Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_null_when_no_match_found(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Color', 'Purple'),
        ]);

        $candidates = [
            self::createVariation(id: 10, options: [self::option('Color', 'Red')]),
            self::createVariation(id: 11, options: [self::option('Color', 'Blue')]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_candidates_list_is_empty(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Color', 'Red'),
        ]);

        $result = $this->matcher->findMatch($target, []);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_target_has_no_options(): void
    {
        $target = self::createVariation(id: 1, options: []);

        $candidates = [
            self::createVariation(id: 10, options: [self::option('Color', 'Red')]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_option_count_differs(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Color', 'Red'),
            self::option('Size', 'Large'),
        ]);

        // Candidate only has one option
        $candidates = [
            self::createVariation(id: 10, options: [
                self::option('Color', 'Red'),
            ]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_option_names_differ(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Color', 'Red'),
        ]);

        // Same value but different option name
        $candidates = [
            self::createVariation(id: 10, options: [
                self::option('Finish', 'Red'),
            ]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | First Match Wins Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_the_first_matching_candidate(): void
    {
        $target = self::createVariation(id: 1, options: [
            self::option('Color', 'Red'),
        ]);

        $candidates = [
            self::createVariation(id: 10, options: [self::option('Color', 'Blue')]),
            self::createVariation(id: 11, options: [self::option('Color', 'Red')]),
            self::createVariation(id: 12, options: [self::option('Color', 'Red')]),
        ];

        $result = $this->matcher->findMatch($target, $candidates);

        self::assertNotNull($result);
        self::assertSame(11, $result->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<ProductVariationOption> $options
     */
    private static function createVariation(int $id, array $options): ProductVariation
    {
        return new ProductVariation(
            id: $id,
            productExternalId: 12345,
            sku: null,
            price: 29.99,
            costPrice: 15.00,
            salePrice: null,
            stock: 10,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
            options: $options,
        );
    }

    private static function option(string $name, string $value): ProductVariationOption
    {
        return new ProductVariationOption(
            optionId: 1,
            optionName: $name,
            valueId: 1,
            valueName: $value,
        );
    }
}
