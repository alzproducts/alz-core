<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Support;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PascalCaseMapper Unit Tests.
 *
 * Tests the Spatie Data name mapper that converts camelCase property names
 * to PascalCase for Linnworks API (.NET convention).
 */
#[CoversClass(PascalCaseMapper::class)]
final class PascalCaseMapperTest extends TestCase
{
    private PascalCaseMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new PascalCaseMapper();
    }

    /*
    |--------------------------------------------------------------------------
    | String Mapping Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('camelCaseToPascalCaseProvider')]
    public function it_maps_camel_case_to_pascal_case(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->mapper->map($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function camelCaseToPascalCaseProvider(): array
    {
        return [
            'simple camelCase' => ['itemNumber', 'ItemNumber'],
            'single word lowercase' => ['stock', 'Stock'],
            'multi-word camelCase' => ['stockItemId', 'StockItemId'],
            'already PascalCase' => ['StockItem', 'StockItem'],
            'single character' => ['a', 'A'],
            'acronym start' => ['xmlParser', 'XmlParser'],
            'with numbers' => ['item123', 'Item123'],
            'numbers at start' => ['123item', '123item'],
            'single uppercase' => ['A', 'A'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Integer Passthrough Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_integer_unchanged(): void
    {
        $this->assertSame(0, $this->mapper->map(0));
        $this->assertSame(42, $this->mapper->map(42));
        $this->assertSame(-1, $this->mapper->map(-1));
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_empty_string(): void
    {
        $this->assertSame('', $this->mapper->map(''));
    }

    #[Test]
    public function it_handles_underscore_prefix(): void
    {
        // ucfirst doesn't affect underscore
        $this->assertSame('_privateField', $this->mapper->map('_privateField'));
    }
}
