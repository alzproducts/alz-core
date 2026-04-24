<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
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

    /**
     * Collapse the DTO into an Application-layer command carrying only the
     * touched fields. Enum-backed columns are resolved here — the `#[Enum]`
     * validator already rejected malformed values upstream, so `from()` cannot
     * throw at runtime.
     */
    public function toCommand(): SaveCustomFieldGeneralSettingsCommand
    {
        $touchedKeys = [];

        [$tooltip, $touchedKeys] = self::resolveString($this->tooltip, 'tooltip', $touchedKeys);
        [$selectType, $touchedKeys] = self::resolveSelectType($this->select_type, $touchedKeys);
        [$suggestCommonData, $touchedKeys] = self::resolveNullableBool($this->suggest_common_data, 'suggest_common_data', $touchedKeys);
        [$adminOnly, $touchedKeys] = self::resolveBool($this->admin_only, $touchedKeys);
        [$validationRule, $touchedKeys] = self::resolveValidationRule($this->field_validation_rule, $touchedKeys);

        return new SaveCustomFieldGeneralSettingsCommand(
            tooltip: $tooltip,
            selectType: $selectType,
            suggestCommonData: $suggestCommonData,
            adminOnly: $adminOnly,
            validationRule: $validationRule,
            touchedKeys: $touchedKeys,
        );
    }

    /**
     * @param list<string> $touchedKeys
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private static function resolveString(Optional|string|null $value, string $key, array $touchedKeys): array
    {
        if ($value instanceof Optional) {
            return [null, $touchedKeys];
        }

        $touchedKeys[] = $key;

        return [$value, $touchedKeys];
    }

    /**
     * @param list<string> $touchedKeys
     *
     * @return array{0: bool|null, 1: list<string>}
     */
    private static function resolveNullableBool(Optional|bool|null $value, string $key, array $touchedKeys): array
    {
        if ($value instanceof Optional) {
            return [null, $touchedKeys];
        }

        $touchedKeys[] = $key;

        return [$value, $touchedKeys];
    }

    /**
     * @param list<string> $touchedKeys
     *
     * @return array{0: bool|null, 1: list<string>}
     */
    private static function resolveBool(Optional|bool $value, array $touchedKeys): array
    {
        if ($value instanceof Optional) {
            return [null, $touchedKeys];
        }

        $touchedKeys[] = 'admin_only';

        return [$value, $touchedKeys];
    }

    /**
     * @param list<string> $touchedKeys
     *
     * @return array{0: CustomFieldValueSelectType|null, 1: list<string>}
     */
    private static function resolveSelectType(Optional|string|null $value, array $touchedKeys): array
    {
        if ($value instanceof Optional) {
            return [null, $touchedKeys];
        }

        $touchedKeys[] = 'select_type';
        $resolved = $value === null ? null : CustomFieldValueSelectType::from($value);

        return [$resolved, $touchedKeys];
    }

    /**
     * @param list<string> $touchedKeys
     *
     * @return array{0: CustomFieldValidationRule|null, 1: list<string>}
     */
    private static function resolveValidationRule(Optional|int|null $value, array $touchedKeys): array
    {
        if ($value instanceof Optional) {
            return [null, $touchedKeys];
        }

        $touchedKeys[] = 'field_validation_rule';
        $resolved = $value === null ? null : CustomFieldValidationRule::from($value);

        return [$resolved, $touchedKeys];
    }
}
