<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Shopwired\CustomFields\CustomFieldDefinitionRegistry;
use App\Infrastructure\Shopwired\Factories\CustomFieldFactory;
use App\Infrastructure\Shopwired\Factories\CustomFieldValueFactory;
use Webmozart\Assert\Assert;

/**
 * Inputs every page product shares, decoded from the statement's `shared_inputs` row:
 * the custom-field definitions the list assembler types custom fields against.
 */
final readonly class SharedListInputs
{
    private function __construct(
        public CustomFieldDefinitionRegistry $productFields,
        public bool $hasDefinitions,
    ) {}

    public static function fromJson(string $json): self
    {
        $definitions = \array_map(
            self::definition(...),
            ProductListValue::objectList(ProductListValue::decode($json), 'custom_field_definitions'),
        );

        return new self(
            CustomFieldDefinitionRegistry::forItemType($definitions, CustomFieldItemType::Product),
            $definitions !== [],
        );
    }

    /**
     * Types a product's raw custom fields as {@see CustomFieldFactory} does, minus its unknown-field reporting.
     *
     * @param array<string, mixed> $rawFields
     *
     * @throws InvalidCustomFieldValueException When a value type mismatches its definition
     * @throws MissingRequiredDataException When there are raw fields but no definitions
     */
    public function typeProductFields(array $rawFields): CustomFieldValueList
    {
        $this->assertDefinitionsFor($rawFields);
        $typed = [];

        foreach ($rawFields as $name => $value) {
            $definition = $this->productFields->findByName($name);

            if ($definition !== null && $value !== null) {
                $typed[] = CustomFieldValueFactory::createTypedValueFromDefinition($definition, $value);
            }
        }

        return CustomFieldValueList::from($typed);
    }

    /**
     * @param array<string, mixed> $rawFields
     *
     * @throws MissingRequiredDataException
     */
    private function assertDefinitionsFor(array $rawFields): void
    {
        if ($rawFields !== [] && ! $this->hasDefinitions) {
            throw new MissingRequiredDataException(
                dataType: 'custom field definitions',
                operation: 'ProductListViewAssembler (product)',
                resolution: 'Run php artisan dev:seed-fake (synthetic) or dev:sync-from-prod (opt-in prod sync)',
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function definition(array $row): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            internalId: Uuid::fromTrusted(ProductListValue::string($row, 'id')),
            base: new CustomFieldDefinition(
                id: ProductListValue::int($row, 'external_id'),
                name: ProductListValue::string($row, 'name'),
                type: self::type($row),
                label: ProductListValue::nullableString($row, 'label'),
                itemType: self::itemType($row),
                sortOrder: ProductListValue::nullableInt($row, 'sort_order'),
                allowedValues: ProductListValue::nullableStringValues($row, 'allowed_values'),
            ),
            generalSettings: null,
            productSettings: null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function type(array $row): CustomFieldType
    {
        $type = CustomFieldType::tryFrom(ProductListValue::string($row, 'type'));
        Assert::notNull($type, 'Custom field definition has an unknown type');

        return $type;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function itemType(array $row): CustomFieldItemType
    {
        $itemType = CustomFieldItemType::tryFrom(ProductListValue::string($row, 'item_type'));
        Assert::notNull($itemType, 'Custom field definition has an unknown item type');

        return $itemType;
    }
}
