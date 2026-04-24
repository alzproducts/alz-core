<?php

declare(strict_types=1);

namespace App\Application\Catalog\Commands;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;

/**
 * Partial change set for `catalog.custom_field_general_settings`.
 *
 * Only columns whose name appears in {@see $touchedKeys} are written. Property
 * values for untouched columns are `null` and MUST be ignored by the repository;
 * the DB defaults (or the previous row state) cover them.
 */
final readonly class SaveCustomFieldGeneralSettingsCommand
{
    /**
     * @param list<string> $touchedKeys DB column names present in the original request payload.
     */
    public function __construct(
        public ?string $tooltip,
        public ?CustomFieldValueSelectType $selectType,
        public ?bool $suggestCommonData,
        public ?bool $adminOnly,
        public ?CustomFieldValidationRule $validationRule,
        public array $touchedKeys,
    ) {}
}
