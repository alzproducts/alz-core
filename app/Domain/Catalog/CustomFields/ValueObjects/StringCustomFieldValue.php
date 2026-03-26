<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Custom field value containing a string.
 *
 * Used for field types:
 * - Text: Free-form text input
 * - Choice: Radio button selection (single value from allowedValues)
 * - List: Dropdown selection (single value from allowedValues)
 *
 * Note: Allowed-values validation is a write-time concern handled by
 * CustomFieldValueFactory::fromRawFields(). The VO tolerates stale choice
 * values so the read path never 500s on data that was valid when saved.
 */
final readonly class StringCustomFieldValue extends AbstractCustomFieldValue
{
    public function __construct(
        CustomFieldDefinition $definition,
        public string $value,
    ) {
        Assert::true(
            $definition->type->isStringType(),
            "StringCustomFieldValue requires string type (Text/Choice/List), got '{$definition->type->value}'",
        );

        parent::__construct($definition);
    }

    public function rawValue(): string
    {
        return $this->value;
    }
}
