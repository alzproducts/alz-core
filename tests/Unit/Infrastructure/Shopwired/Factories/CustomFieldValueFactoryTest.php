<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Factories;

use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Infrastructure\Shopwired\Factories\CustomFieldValueFactory;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class CustomFieldValueFactoryTest extends TestCase
{
    private CustomFieldRepositoryInterface&MockInterface $repository;

    private CustomFieldValueFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(CustomFieldRepositoryInterface::class);
        $this->factory = new CustomFieldValueFactory($this->repository, CustomFieldItemType::Product);
    }

    // ========================================================================
    // Write-path choice validation
    // ========================================================================

    #[Test]
    public function from_raw_fields_accepts_valid_choice_value(): void
    {
        $this->stubRegistryWith([
            $this->createChoiceDefinition('color', ['Red', 'Green', 'Blue']),
        ]);

        $result = $this->factory->fromRawFields(['color' => 'Red']);

        self::assertCount(1, $result);
        self::assertInstanceOf(StringCustomFieldValue::class, $result[0]);
        self::assertSame('Red', $result[0]->rawValue());
    }

    #[Test]
    public function from_raw_fields_throws_on_invalid_choice_value(): void
    {
        $this->stubRegistryWith([
            $this->createChoiceDefinition('color', ['Red', 'Green', 'Blue']),
        ]);

        try {
            $this->factory->fromRawFields(['color' => 'Yellow']);
            self::fail('Expected InvalidCustomFieldValueException');
        } catch (InvalidCustomFieldValueException $e) {
            self::assertSame('color', $e->fieldName);
            self::assertSame(CustomFieldType::Choice, $e->expectedType);
            self::assertSame('string (invalid choice)', $e->actualType);
            self::assertSame('Yellow', $e->rawValue);
        }
    }

    #[Test]
    public function from_raw_fields_throws_on_invalid_list_value(): void
    {
        $this->stubRegistryWith([
            $this->createListDefinition('size', ['Small', 'Medium', 'Large']),
        ]);

        $this->expectException(InvalidCustomFieldValueException::class);

        $this->factory->fromRawFields(['size' => 'Extra Large']);
    }

    #[Test]
    public function from_raw_fields_skips_choice_check_for_non_string_values(): void
    {
        $this->stubRegistryWith([
            $this->createChoiceDefinition('color', ['Red', 'Green', 'Blue']),
        ]);

        $this->expectException(InvalidCustomFieldValueException::class);

        $this->factory->fromRawFields(['color' => 123]);
    }

    // ========================================================================
    // Null values — "clear this field"
    // ========================================================================

    #[Test]
    public function from_raw_fields_accepts_null_value_to_clear_field(): void
    {
        $this->stubRegistryWith([
            $this->createListDefinition('size', ['Small', 'Medium', 'Large']),
        ]);

        $result = $this->factory->fromRawFields(['size' => null]);

        self::assertSame([], $result);
    }

    #[Test]
    public function from_raw_fields_mixes_null_and_typed_values(): void
    {
        $this->stubRegistryWith([
            $this->createChoiceDefinition('color', ['Red', 'Green', 'Blue']),
            $this->createListDefinition('size', ['Small', 'Medium', 'Large']),
        ]);

        $result = $this->factory->fromRawFields(['color' => 'Red', 'size' => null]);

        self::assertCount(1, $result);
        self::assertSame('Red', $result[0]->rawValue());
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param list<ConfiguredFieldDefinition> $definitions
     */
    private function stubRegistryWith(array $definitions): void
    {
        $this->repository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn($definitions);
    }

    /**
     * @param list<string> $allowedValues
     */
    private function createChoiceDefinition(string $name, array $allowedValues): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 1,
            name: $name,
            type: CustomFieldType::Choice,
            label: \ucfirst($name),
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: $allowedValues,
        ));
    }

    /**
     * @param list<string> $allowedValues
     */
    private function createListDefinition(string $name, array $allowedValues): ConfiguredFieldDefinition
    {
        return self::wrap(new CustomFieldDefinition(
            id: 2,
            name: $name,
            type: CustomFieldType::List,
            label: \ucfirst($name),
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: $allowedValues,
        ));
    }

    private static function wrap(CustomFieldDefinition $base): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition($base, CustomFieldGeneralSettings::defaults(), null);
    }
}
