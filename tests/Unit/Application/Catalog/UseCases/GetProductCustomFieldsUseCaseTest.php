<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Catalog\UseCases\GetProductCustomFieldsUseCase;
use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\NullCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(GetProductCustomFieldsUseCase::class)]
final class GetProductCustomFieldsUseCaseTest extends TestCase
{
    private ProductRepositoryInterface&MockInterface $productRepository;

    private CustomFieldRepositoryInterface&MockInterface $customFieldRepository;

    private LoggerInterface&MockInterface $logger;

    private GetProductCustomFieldsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);
        $this->customFieldRepository = Mockery::mock(CustomFieldRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new GetProductCustomFieldsUseCase(
            $this->productRepository,
            $this->customFieldRepository,
            $this->logger,
        );
    }

    // ========================================================================
    // Happy Path — Merge populated + empty fields
    // ========================================================================

    #[Test]
    public function returns_all_defined_fields_including_null_for_missing(): void
    {
        $colourDef = $this->createDefinition('colour', CustomFieldType::Choice, sortOrder: 0, allowedValues: ['Red', 'Blue']);
        $notesDef = $this->createDefinition('notes', CustomFieldType::Text, sortOrder: 1);
        $sizeDef = $this->createDefinition('size', CustomFieldType::Choice, sortOrder: 2, allowedValues: ['S', 'M', 'L']);

        // Product only has 'colour' populated
        $colourField = new StringCustomFieldValue($colourDef, 'Red');
        $this->stubProduct([$colourField]);
        $this->stubDefinitions([$colourDef, $notesDef, $sizeDef]);

        $result = $this->useCase->execute(42);

        self::assertCount(3, $result);

        // colour: populated
        self::assertSame('colour', $result[0]->name());
        self::assertSame('Red', $result[0]->rawValue());

        // notes: null (not on product)
        self::assertSame('notes', $result[1]->name());
        self::assertInstanceOf(NullCustomFieldValue::class, $result[1]);
        self::assertNull($result[1]->rawValue());

        // size: null (not on product)
        self::assertSame('size', $result[2]->name());
        self::assertInstanceOf(NullCustomFieldValue::class, $result[2]);
    }

    // ========================================================================
    // Sorting
    // ========================================================================

    #[Test]
    public function sorts_fields_by_sort_order_null_last(): void
    {
        $fieldC = $this->createDefinition('field_c', CustomFieldType::Text, sortOrder: 2);
        $fieldA = $this->createDefinition('field_a', CustomFieldType::Text, sortOrder: 0);
        $fieldNull = $this->createDefinition('field_null', CustomFieldType::Text, sortOrder: null);
        $fieldB = $this->createDefinition('field_b', CustomFieldType::Text, sortOrder: 1);

        $this->stubProduct([]);
        $this->stubDefinitions([$fieldC, $fieldA, $fieldNull, $fieldB]);

        $result = $this->useCase->execute(42);

        self::assertCount(4, $result);
        self::assertSame('field_a', $result[0]->name());
        self::assertSame('field_b', $result[1]->name());
        self::assertSame('field_c', $result[2]->name());
        self::assertSame('field_null', $result[3]->name());
    }

    // ========================================================================
    // Filtering
    // ========================================================================

    #[Test]
    public function filters_fields_by_name_after_merge(): void
    {
        $colourDef = $this->createDefinition('colour', CustomFieldType::Choice, sortOrder: 0, allowedValues: ['Red']);
        $notesDef = $this->createDefinition('notes', CustomFieldType::Text, sortOrder: 1);
        $sizeDef = $this->createDefinition('size', CustomFieldType::Choice, sortOrder: 2, allowedValues: ['S']);

        $colourField = new StringCustomFieldValue($colourDef, 'Red');
        $this->stubProduct([$colourField]);
        $this->stubDefinitions([$colourDef, $notesDef, $sizeDef]);

        // Filter to 'colour' and 'size' — size has no value but should still appear
        $result = $this->useCase->execute(42, ['colour', 'size']);

        self::assertCount(2, $result);
        self::assertSame('colour', $result[0]->name());
        self::assertSame('Red', $result[0]->rawValue());
        self::assertSame('size', $result[1]->name());
        self::assertInstanceOf(NullCustomFieldValue::class, $result[1]);
    }

    #[Test]
    public function returns_empty_when_no_fields_match_filter(): void
    {
        $colourDef = $this->createDefinition('colour', CustomFieldType::Text, sortOrder: 0);

        $colourField = new StringCustomFieldValue($colourDef, 'Red');
        $this->stubProduct([$colourField]);
        $this->stubDefinitions([$colourDef]);

        $result = $this->useCase->execute(42, ['nonexistent_field']);

        self::assertSame([], $result);
    }

    // ========================================================================
    // Forward compatibility — fields not in definitions
    // ========================================================================

    #[Test]
    public function includes_populated_fields_not_in_definitions(): void
    {
        $knownDef = $this->createDefinition('known', CustomFieldType::Text, sortOrder: 0);
        $unknownDef = $this->createDefinition('unknown_field', CustomFieldType::Text, sortOrder: null);

        $knownField = new StringCustomFieldValue($knownDef, 'value1');
        $unknownField = new StringCustomFieldValue($unknownDef, 'value2');

        $this->stubProduct([$knownField, $unknownField]);
        // Only 'known' is in definitions — 'unknown_field' should still appear
        $this->stubDefinitions([$knownDef]);

        $result = $this->useCase->execute(42);

        self::assertCount(2, $result);
        // known is sorted first (sortOrder 0), unknown_field last (null sortOrder)
        self::assertSame('known', $result[0]->name());
        self::assertSame('unknown_field', $result[1]->name());
        self::assertSame('value2', $result[1]->rawValue());
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param list<AbstractCustomFieldValue> $customFields
     */
    private function stubProduct(array $customFields): void
    {
        $product = Mockery::mock(ProductView::class);
        $product->customFields = $customFields;

        $this->productRepository
            ->shouldReceive('findProductForApi')
            ->once()
            ->with(Mockery::on(static fn(ProductDetailQueryParams $q): bool => $q->productId->value === 42 && $q->includes === [ProductInclude::CustomFields]))
            ->andReturn($product);
    }

    /**
     * @param list<CustomFieldDefinition> $definitions
     */
    private function stubDefinitions(array $definitions): void
    {
        $this->customFieldRepository
            ->shouldReceive('findByItemType')
            ->once()
            ->with(CustomFieldItemType::Product)
            ->andReturn($definitions);
    }

    /**
     * @param list<string>|null $allowedValues
     */
    private function createDefinition(
        string $name,
        CustomFieldType $type,
        ?int $sortOrder,
        ?array $allowedValues = null,
    ): CustomFieldDefinition {
        static $idCounter = 0;

        return new CustomFieldDefinition(
            id: ++$idCounter,
            name: $name,
            type: $type,
            label: \ucfirst(\str_replace('_', ' ', $name)),
            itemType: CustomFieldItemType::Product,
            sortOrder: $sortOrder,
            allowedValues: $allowedValues,
        );
    }
}
