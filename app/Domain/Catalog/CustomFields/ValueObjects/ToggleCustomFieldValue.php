<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Custom field value containing a boolean.
 *
 * Used for field type:
 * - Toggle: Boolean toggle (true/false)
 */
final readonly class ToggleCustomFieldValue extends AbstractCustomFieldValue
{
    public function __construct(
        CustomFieldDefinition $definition,
        public bool $value,
    ) {
        Assert::true(
            $definition->type->isBooleanType(),
            "ToggleCustomFieldValue requires boolean type (Toggle), got '{$definition->type->value}'",
        );

        parent::__construct($definition);
    }

    public function rawValue(): bool
    {
        return $this->value;
    }
}
