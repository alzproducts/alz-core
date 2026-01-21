<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use Webmozart\Assert\Assert;

/**
 * Custom field value containing an array of strings.
 *
 * Used for field type:
 * - ValueList: User-entered array of string values
 */
final readonly class ValueListCustomFieldValue extends AbstractCustomFieldValue
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        CustomFieldDefinition $definition,
        public array $values,
    ) {
        Assert::same(
            $definition->type,
            CustomFieldType::ValueList,
            "ValueListCustomFieldValue requires ValueList type, got '{$definition->type->value}'",
        );
        // @phpstan-ignore staticMethod.alreadyNarrowedType (runtime validation for external data)
        Assert::allString($values, 'ValueList values must all be strings');

        parent::__construct($definition);
    }

    /**
     * @return list<string>
     */
    public function rawValue(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function count(): int
    {
        return \count($this->values);
    }
}
