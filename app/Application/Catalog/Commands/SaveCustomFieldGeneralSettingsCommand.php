<?php

declare(strict_types=1);

namespace App\Application\Catalog\Commands;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldGeneralSettingsField;
use Webmozart\Assert\Assert;

/**
 * Partial change set for `catalog.custom_field_general_settings`.
 *
 * Three states encoded across two structural positions (no null overloading):
 * - column key in {@see $valuesToSet}    → write that value to the column
 * - case in {@see $columnsToClear}       → write NULL to the column
 * - column in neither                    → leave the column untouched
 *
 * Constructor enforces three invariants: every key in `$valuesToSet` is a valid
 * column name; no column appears in both maps; only nullable columns may be
 * cleared.
 */
final readonly class SaveCustomFieldGeneralSettingsCommand
{
    /**
     * @param array<string, scalar>                 $valuesToSet    keyed by DB column name
     * @param list<CustomFieldGeneralSettingsField> $columnsToClear
     */
    public function __construct(
        public array $valuesToSet,
        public array $columnsToClear,
    ) {
        $validColumns = \array_map(
            static fn(CustomFieldGeneralSettingsField $c): string => $c->value,
            CustomFieldGeneralSettingsField::cases(),
        );

        Assert::allOneOf(
            \array_keys($valuesToSet),
            $validColumns,
            'Unknown column in valuesToSet: %s.',
        );

        $clearedColumnNames = \array_map(
            static fn(CustomFieldGeneralSettingsField $c): string => $c->value,
            $columnsToClear,
        );

        Assert::isEmpty(
            \array_intersect(\array_keys($valuesToSet), $clearedColumnNames),
            'A column cannot appear in both valuesToSet and columnsToClear.',
        );

        Assert::allTrue(
            \array_map(
                static fn(CustomFieldGeneralSettingsField $c): bool => $c->isClearable(),
                $columnsToClear,
            ),
            'Only clearable columns may appear in columnsToClear.',
        );
    }
}
