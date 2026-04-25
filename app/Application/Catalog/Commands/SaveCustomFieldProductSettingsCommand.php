<?php

declare(strict_types=1);

namespace App\Application\Catalog\Commands;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldProductSettingsField;
use Webmozart\Assert\Assert;

/**
 * Partial change set for `catalog.custom_field_product_settings`.
 *
 * Three states encoded across two structural positions (no null overloading):
 * - column key in {@see $valuesToSet}    → write that value to the column
 * - case in {@see $columnsToClear}       → write NULL to the column
 * - column in neither                    → leave the column untouched
 */
final readonly class SaveCustomFieldProductSettingsCommand
{
    /**
     * @param array<string, scalar>                 $valuesToSet    keyed by DB column name
     * @param list<CustomFieldProductSettingsField> $columnsToClear
     */
    public function __construct(
        public array $valuesToSet,
        public array $columnsToClear,
    ) {
        $validColumns = \array_map(
            static fn(CustomFieldProductSettingsField $c): string => $c->value,
            CustomFieldProductSettingsField::cases(),
        );

        Assert::allOneOf(
            \array_keys($valuesToSet),
            $validColumns,
            'Unknown column in valuesToSet: %s.',
        );

        $clearedColumnNames = \array_map(
            static fn(CustomFieldProductSettingsField $c): string => $c->value,
            $columnsToClear,
        );

        Assert::isEmpty(
            \array_intersect(\array_keys($valuesToSet), $clearedColumnNames),
            'A column cannot appear in both valuesToSet and columnsToClear.',
        );

        Assert::allTrue(
            \array_map(
                static fn(CustomFieldProductSettingsField $c): bool => $c->isClearable(),
                $columnsToClear,
            ),
            'Only clearable columns may appear in columnsToClear.',
        );
    }
}
