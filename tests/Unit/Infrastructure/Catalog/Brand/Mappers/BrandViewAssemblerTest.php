<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\Brand\Mappers;

use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Infrastructure\Catalog\Brand\Mappers\BrandViewAssembler;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Models\BrandModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrandViewAssembler::class)]
final class BrandViewAssemblerTest extends TestCase
{
    private CustomFieldFactory&MockInterface $customFieldFactory;

    private BrandViewAssembler $assembler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customFieldFactory = Mockery::mock(CustomFieldFactory::class);
        $this->assembler = new BrandViewAssembler($this->customFieldFactory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================================================
    // description2 — populated from custom_fields when Description is included
    // ========================================================================

    #[Test]
    public function it_populates_description2_from_custom_fields_when_description_include_is_present(): void
    {
        $model = self::buildBrandModel(customFields: ['description2' => '<p>Secondary content</p>']);

        $result = $this->assembler->toViewDomain($model, [BrandInclude::Description]);

        self::assertSame('<p>Secondary content</p>', $result->description2);
    }

    #[Test]
    public function it_returns_null_description2_when_custom_field_is_missing(): void
    {
        $model = self::buildBrandModel(customFields: ['some_other_field' => 'value']);

        $result = $this->assembler->toViewDomain($model, [BrandInclude::Description]);

        self::assertNull($result->description2);
    }

    #[Test]
    public function it_returns_null_description2_when_description_include_is_absent(): void
    {
        $model = self::buildBrandModel(customFields: ['description2' => '<p>Secondary content</p>']);

        $result = $this->assembler->toViewDomain($model, []);

        self::assertNull($result->description2);
    }

    // ========================================================================
    // description2 — excluded from custom_fields collection
    // ========================================================================

    #[Test]
    public function it_filters_description2_from_custom_fields_when_custom_fields_include_is_present(): void
    {
        $model = self::buildBrandModel(customFields: [
            'description2' => '<p>Secondary</p>',
            'material' => 'Cotton',
        ]);

        $typedField = Mockery::mock(StringCustomFieldValue::class);

        $this->customFieldFactory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with(Mockery::on(static fn(array $fields): bool => ! \array_key_exists('description2', $fields)
                    && \array_key_exists('material', $fields)
                    && $fields['material'] === 'Cotton'))
            ->andReturn([$typedField]);

        $result = $this->assembler->toViewDomain($model, [BrandInclude::CustomFields]);

        self::assertSame([$typedField], $result->customFields);
    }

    #[Test]
    public function it_filters_description2_even_when_no_other_custom_fields_exist(): void
    {
        $model = self::buildBrandModel(customFields: ['description2' => '<p>Only field</p>']);

        $this->customFieldFactory
            ->shouldReceive('fromRawFields')
            ->once()
            ->with([])
            ->andReturn([]);

        $result = $this->assembler->toViewDomain($model, [BrandInclude::CustomFields]);

        self::assertSame([], $result->customFields);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param array<string, mixed> $customFields
     */
    private static function buildBrandModel(array $customFields = []): BrandModel&MockInterface
    {
        $createdAt = Mockery::mock(CarbonImmutable::class);
        $createdAt->shouldReceive('toDateTimeImmutable')
            ->andReturn(new DateTimeImmutable('2025-01-01'));

        $model = Mockery::mock(BrandModel::class);
        $model->shouldReceive('getAttribute')->with('external_id')->andReturn(42);
        $model->shouldReceive('getAttribute')->with('title')->andReturn('Test Brand');
        $model->shouldReceive('getAttribute')->with('slug')->andReturn('test-brand');
        $model->shouldReceive('getAttribute')->with('url')->andReturn('/brands/test-brand');
        $model->shouldReceive('getAttribute')->with('active')->andReturn(true);
        $model->shouldReceive('getAttribute')->with('featured')->andReturn(false);
        $model->shouldReceive('getAttribute')->with('sort_order')->andReturn(1);
        $model->shouldReceive('getAttribute')->with('meta_title')->andReturn(null);
        $model->shouldReceive('getAttribute')->with('meta_description')->andReturn(null);
        $model->shouldReceive('getAttribute')->with('image_url')->andReturn(null);
        $model->shouldReceive('getAttribute')->with('shopwired_created_at')->andReturn($createdAt);
        $model->shouldReceive('getAttribute')->with('description')->andReturn('Primary description');
        $model->shouldReceive('getAttribute')->with('custom_fields')->andReturn($customFields);
        $model->shouldReceive('offsetExists')->with('custom_fields')->andReturn(true);

        return $model;
    }
}
