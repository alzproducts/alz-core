<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldGeneralSettingsField;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use App\Presentation\Http\Api\Support\MergePatchMapper;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * PUT body for `catalog.custom_field_general_settings`.
 *
 * Partial-update semantics via {@see Optional}:
 * - field absent from body → property is `Optional` → no change
 * - field sent as `null`   → property is `null`     → clear the column
 * - field sent with value  → property is the value  → set the column
 *
 * `admin_only` cannot be null — the DB default handles first-create.
 */
final class UpdateCustomFieldGeneralSettingsRequestDTO extends Data
{
    public function __construct(
        #[Max(500)]
        public readonly Optional|string|null $tooltip = new Optional(),
        #[Enum(CustomFieldValueSelectType::class)]
        public readonly Optional|string|null $select_type = new Optional(),
        public readonly Optional|bool|null $suggest_common_data = new Optional(),
        public readonly Optional|bool $admin_only = new Optional(),
        #[Enum(CustomFieldValidationRule::class)]
        public readonly Optional|int|null $field_validation_rule = new Optional(),
    ) {}

    public function toCommand(): SaveCustomFieldGeneralSettingsCommand
    {
        [$valuesToSet, $columnsToClear] = MergePatchMapper::buildMaps([
            [CustomFieldGeneralSettingsField::Tooltip, $this->tooltip],
            [CustomFieldGeneralSettingsField::SelectType, $this->select_type],
            [CustomFieldGeneralSettingsField::SuggestCommonData, $this->suggest_common_data],
            [CustomFieldGeneralSettingsField::AdminOnly, $this->admin_only],
            [CustomFieldGeneralSettingsField::ValidationRule, $this->field_validation_rule],
        ]);

        return new SaveCustomFieldGeneralSettingsCommand(
            valuesToSet: $valuesToSet,
            columnsToClear: $columnsToClear,
        );
    }
}
