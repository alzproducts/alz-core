<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\RelatedProducts\ValueObjects;

use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RelatedProductsAlgorithmParams::class)]
final class RelatedProductsAlgorithmParamsTest extends TestCase
{
    #[Test]
    public function constructor_stores_all_properties(): void
    {
        $params = new RelatedProductsAlgorithmParams(
            categoryWeight: 1.5,
            titleWeight: 2.0,
            popularityWeight: 0.5,
            maxResults: 10,
            minContentScore: 0.25,
            defaultPopularity: 0.1,
            excludeCompareList: true,
        );

        self::assertSame(1.5, $params->categoryWeight);
        self::assertSame(2.0, $params->titleWeight);
        self::assertSame(0.5, $params->popularityWeight);
        self::assertSame(10, $params->maxResults);
        self::assertSame(0.25, $params->minContentScore);
        self::assertSame(0.1, $params->defaultPopularity);
        self::assertTrue($params->excludeCompareList);
    }

    #[Test]
    public function constructor_preserves_false_exclude_compare_list(): void
    {
        $params = $this->validParams(excludeCompareList: false);

        self::assertFalse($params->excludeCompareList);
    }

    #[Test]
    public function constructor_accepts_zero_min_content_score(): void
    {
        $params = $this->validParams(minContentScore: 0.0);

        self::assertSame(0.0, $params->minContentScore);
    }

    #[Test]
    public function constructor_accepts_min_results_boundary(): void
    {
        $params = $this->validParams(maxResults: 2);

        self::assertSame(2, $params->maxResults);
    }

    #[Test]
    public function constructor_accepts_max_results_boundary(): void
    {
        $params = $this->validParams(maxResults: 20);

        self::assertSame(20, $params->maxResults);
    }

    #[Test]
    #[DataProvider('invalidCategoryWeightProvider')]
    public function constructor_rejects_non_positive_category_weight(float $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('category_weight must be positive');

        $this->validParams(categoryWeight: $invalid);
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function invalidCategoryWeightProvider(): array
    {
        return [
            'zero' => [0.0],
            'negative' => [-1.0],
        ];
    }

    #[Test]
    public function constructor_rejects_non_positive_title_weight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('title_weight must be positive');

        $this->validParams(titleWeight: 0.0);
    }

    #[Test]
    public function constructor_rejects_non_positive_popularity_weight(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('popularity_weight must be positive');

        $this->validParams(popularityWeight: -0.5);
    }

    #[Test]
    #[DataProvider('outOfRangeMaxResultsProvider')]
    public function constructor_rejects_max_results_outside_2_to_20(int $invalid): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max_results must be between 2 and 20');

        $this->validParams(maxResults: $invalid);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function outOfRangeMaxResultsProvider(): array
    {
        return [
            'one' => [1],
            'zero' => [0],
            'negative' => [-1],
            'twenty-one' => [21],
            'large' => [100],
        ];
    }

    #[Test]
    public function constructor_rejects_negative_min_content_score(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('min_content_score must be non-negative');

        $this->validParams(minContentScore: -0.01);
    }

    #[Test]
    public function constructor_rejects_non_positive_default_popularity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default_popularity must be positive');

        $this->validParams(defaultPopularity: 0.0);
    }

    private function validParams(
        float $categoryWeight = 1.0,
        float $titleWeight = 1.0,
        float $popularityWeight = 1.0,
        int $maxResults = 5,
        float $minContentScore = 0.1,
        float $defaultPopularity = 0.1,
        bool $excludeCompareList = true,
    ): RelatedProductsAlgorithmParams {
        return new RelatedProductsAlgorithmParams(
            categoryWeight: $categoryWeight,
            titleWeight: $titleWeight,
            popularityWeight: $popularityWeight,
            maxResults: $maxResults,
            minContentScore: $minContentScore,
            defaultPopularity: $defaultPopularity,
            excludeCompareList: $excludeCompareList,
        );
    }
}
