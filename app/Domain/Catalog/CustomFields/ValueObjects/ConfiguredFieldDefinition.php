<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Read-path composition: a ShopWired custom field definition paired with local settings.
 *
 * ShopWired owns the sync contract via {@see CustomFieldDefinition} (immutable, identical
 * per field type). Local presentation/behaviour overrides live in two settings VOs that
 * reference the definition by UUID. This wrapper carries the combined read-model.
 *
 * The inner ShopWired definition is exposed as `$base` (not `$definition`) so callers
 * inside the value hierarchy read `$this->definition->base->name` instead of the noisier
 * `$this->definition->definition->name`.
 *
 * No delegation accessors are provided — callers reach through `->base` directly. This
 * keeps the wrapper a pure structural pass-through and avoids maintaining a parallel
 * API that duplicates {@see CustomFieldDefinition}'s surface.
 */
final readonly class ConfiguredFieldDefinition
{
    public function __construct(
        public CustomFieldDefinition $base,
        public CustomFieldGeneralSettings $generalSettings,
        public ?ProductFieldSettings $productSettings,
    ) {
        Assert::true(
            $productSettings === null || $base->isProductField(),
            'ProductFieldSettings can only be attached to Product-type custom fields',
        );
    }
}
