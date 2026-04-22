<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog;

use App\Application\Catalog\CustomFieldMergerService;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\NullCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldMergerService::class)]
final class CustomFieldMergerServiceTest extends TestCase
{
    #[Test]
    public function populated_fields_matching_definitions_are_returned_in_sort_order(): void
    {
        $defA = $this->makeDefinition(1, 'color', sortOrder: 2);
        $defB = $this->makeDefinition(2, 'size', sortOrder: 1);

        $fieldA = new StringCustomFieldValue($defA, 'red');
        $fieldB = new StringCustomFieldValue($defB, 'large');

        $result = CustomFieldMergerService::mergeWithDefinitions([$fieldA, $fieldB], [$defA, $defB]);

        self::assertCount(2, $result);
        // sortOrder 1 (size) comes before sortOrder 2 (color)
        self::assertSame('large', $result[0]->rawValue());
        self::assertSame('red', $result[1]->rawValue());
    }

    #[Test]
    public function unpopulated_definitions_receive_null_custom_field_value(): void
    {
        $defA = $this->makeDefinition(1, 'color', sortOrder: 1);
        $defB = $this->makeDefinition(2, 'size', sortOrder: 2);
        $defC = $this->makeDefinition(3, 'material', sortOrder: 3);

        $fieldA = new StringCustomFieldValue($defA, 'red');

        $result = CustomFieldMergerService::mergeWithDefinitions([$fieldA], [$defA, $defB, $defC]);

        self::assertCount(3, $result);
        self::assertInstanceOf(StringCustomFieldValue::class, $result[0]);
        self::assertInstanceOf(NullCustomFieldValue::class, $result[1]);
        self::assertInstanceOf(NullCustomFieldValue::class, $result[2]);
    }

    #[Test]
    public function extra_populated_fields_not_in_definitions_are_appended(): void
    {
        $defA = $this->makeDefinition(1, 'color', sortOrder: 1);
        $defB = $this->makeDefinition(2, 'size', sortOrder: null);

        $fieldA = new StringCustomFieldValue($defA, 'red');
        $fieldB = new StringCustomFieldValue($defB, 'large');

        // Only defA is in definitions; fieldB has no matching definition
        $result = CustomFieldMergerService::mergeWithDefinitions([$fieldA, $fieldB], [$defA]);

        self::assertCount(2, $result);
        self::assertSame('red', $result[0]->rawValue());
        self::assertSame('large', $result[1]->rawValue());
    }

    #[Test]
    public function sorts_by_sort_order_with_null_last(): void
    {
        $def3 = $this->makeDefinition(1, 'field_3', sortOrder: 3);
        $def1 = $this->makeDefinition(2, 'field_1', sortOrder: 1);
        $defNull = $this->makeDefinition(3, 'field_null', sortOrder: null);
        $def2 = $this->makeDefinition(4, 'field_2', sortOrder: 2);

        $field3 = new StringCustomFieldValue($def3, 'three');
        $field1 = new StringCustomFieldValue($def1, 'one');
        $fieldNull = new StringCustomFieldValue($defNull, 'null-sort');
        $field2 = new StringCustomFieldValue($def2, 'two');

        $result = CustomFieldMergerService::mergeWithDefinitions(
            [$field3, $field1, $fieldNull, $field2],
            [$def3, $def1, $defNull, $def2],
        );

        self::assertCount(4, $result);
        self::assertSame('one', $result[0]->rawValue());
        self::assertSame('two', $result[1]->rawValue());
        self::assertSame('three', $result[2]->rawValue());
        self::assertSame('null-sort', $result[3]->rawValue());
    }

    #[Test]
    public function both_null_sort_orders_are_both_present(): void
    {
        $defA = $this->makeDefinition(1, 'alpha', sortOrder: null);
        $defB = $this->makeDefinition(2, 'beta', sortOrder: null);

        $fieldA = new StringCustomFieldValue($defA, 'a');
        $fieldB = new StringCustomFieldValue($defB, 'b');

        $result = CustomFieldMergerService::mergeWithDefinitions([$fieldA, $fieldB], [$defA, $defB]);

        self::assertCount(2, $result);
    }

    #[Test]
    public function empty_inputs_return_empty_result(): void
    {
        $result = CustomFieldMergerService::mergeWithDefinitions([], []);

        self::assertSame([], $result);
    }

    #[Test]
    public function empty_definitions_with_populated_fields_returns_all_populated_fields(): void
    {
        $defA = $this->makeDefinition(1, 'color', sortOrder: 1);
        $defB = $this->makeDefinition(2, 'size', sortOrder: 2);

        $fieldA = new StringCustomFieldValue($defA, 'red');
        $fieldB = new StringCustomFieldValue($defB, 'large');

        $result = CustomFieldMergerService::mergeWithDefinitions([$fieldA, $fieldB], []);

        self::assertCount(2, $result);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function makeDefinition(int $id, string $name, ?int $sortOrder): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            new CustomFieldDefinition(
                id: $id,
                name: $name,
                type: CustomFieldType::Text,
                label: \ucfirst($name),
                itemType: CustomFieldItemType::Product,
                sortOrder: $sortOrder,
                allowedValues: null,
            ),
            CustomFieldGeneralSettings::defaults(),
            null,
        );
    }
}
