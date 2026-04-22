<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;

/**
 * Local, presentation/behaviour settings applied to any custom field definition.
 *
 * Paired with a ShopWired {@see CustomFieldDefinition} inside
 * {@see ConfiguredFieldDefinition}. Always present on the wrapper — when no
 * row exists in catalog.custom_field_general_settings, {@see self::defaults()}
 * supplies sensible defaults so downstream code never branches on null.
 */
final readonly class CustomFieldGeneralSettings
{
    public function __construct(
        public ?string $tooltip,
        public ?CustomFieldValueSelectType $selectType,
        public ?bool $suggestCommonData,
        public bool $adminOnly,
        public ?CustomFieldValidationRule $validationRule,
    ) {}

    /**
     * Default settings for a definition with no persisted overrides.
     */
    public static function defaults(): self
    {
        return new self(
            tooltip: null,
            selectType: null,
            suggestCommonData: null,
            adminOnly: false,
            validationRule: null,
        );
    }
}
