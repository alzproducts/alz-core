<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * Mutable columns of `catalog.custom_field_general_settings`.
 *
 * Backing values are the DB column names so cases can be spread directly
 * into upsert attribute arrays. {@see isClearable()} encodes per-column
 * NOT NULL constraints — non-clearable cases must never appear in a
 * partial-update command's `columnsToClear` list.
 */
enum CustomFieldGeneralSettingsField: string
{
    case Tooltip = 'tooltip';
    case SelectType = 'select_type';
    case SuggestCommonData = 'suggest_common_data';
    case AdminOnly = 'admin_only';
    case ValidationRule = 'field_validation_rule';

    public function isClearable(): bool
    {
        return match ($this) {
            self::Tooltip,
            self::SelectType,
            self::SuggestCommonData,
            self::ValidationRule => true,
            self::AdminOnly => false,
        };
    }
}
