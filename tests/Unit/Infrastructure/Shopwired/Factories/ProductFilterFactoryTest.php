<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Infrastructure\Shopwired\Factories\ProductFilterFactory;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProductFilterFactoryTest extends TestCase
{
    private FilterGroupRepositoryInterface&MockInterface $repository;

    private ProductFilterFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(FilterGroupRepositoryInterface::class);
        $this->factory = new ProductFilterFactory($this->repository);
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_converts_raw_filters_to_typed_product_filters(): void
    {
        $sizeDefinition = new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0);
        $colourDefinition = new FilterGroupDefinition(id: 2, title: 'Colour', optionNo: 2, sortOrder: 1);

        $this->repository->shouldReceive('findAll')
            ->once()
            ->andReturn([$sizeDefinition, $colourDefinition]);

        $rawFilters = [
            1 => ['Small', 'Medium'],
            2 => ['Red'],
        ];

        $result = $this->factory->fromRawFilters($rawFilters);

        self::assertCount(2, $result);

        // First filter: Size
        self::assertSame('Size', $result[0]->title());
        self::assertSame(1, $result[0]->optionNo());
        self::assertSame(['Small', 'Medium'], $result[0]->values);

        // Second filter: Colour
        self::assertSame('Colour', $result[1]->title());
        self::assertSame(2, $result[1]->optionNo());
        self::assertSame(['Red'], $result[1]->values);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_raw_filters(): void
    {
        // Repository should NOT be called when raw filters are empty
        $this->repository->shouldNotReceive('findAll');

        $result = $this->factory->fromRawFilters([]);

        self::assertSame([], $result);
    }

    // ========================================================================
    // Unknown optionNo Handling
    // ========================================================================

    #[Test]
    public function it_skips_unknown_option_no_and_logs_warning(): void
    {
        $sizeDefinition = new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0);

        $this->repository->shouldReceive('findAll')
            ->once()
            ->andReturn([$sizeDefinition]);

        Log::shouldReceive('warning')
            ->once()
            ->with('Unknown filter group optionNo in product - re-run SyncFilterGroupsJob', [
                'option_no' => 999,
            ]);

        $rawFilters = [
            1 => ['Small'],
            999 => ['Unknown Value'], // optionNo not in registry
        ];

        $result = $this->factory->fromRawFilters($rawFilters);

        // Should only return the known filter, skipping the unknown
        self::assertCount(1, $result);
        self::assertSame('Size', $result[0]->title());
    }

    // ========================================================================
    // String optionNo Keys (API quirk)
    // ========================================================================

    #[Test]
    public function it_handles_string_option_no_keys_from_json(): void
    {
        // JSON decode returns string keys for numeric object properties
        $sizeDefinition = new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0);

        $this->repository->shouldReceive('findAll')
            ->once()
            ->andReturn([$sizeDefinition]);

        // Simulate JSON decode behavior: string keys
        $rawFilters = [
            '1' => ['Small', 'Large'],
        ];

        $result = $this->factory->fromRawFilters($rawFilters);

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]->optionNo());
    }

    // ========================================================================
    // Registry Caching
    // ========================================================================

    #[Test]
    public function it_lazy_loads_registry_once(): void
    {
        $sizeDefinition = new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0);

        // Should only call findAll ONCE even for multiple fromRawFilters calls
        $this->repository->shouldReceive('findAll')
            ->once()
            ->andReturn([$sizeDefinition]);

        // First call
        $this->factory->fromRawFilters([1 => ['Small']]);

        // Second call - should use cached registry
        $this->factory->fromRawFilters([1 => ['Large']]);

        // Mockery verifies the "once" constraint
    }
}
