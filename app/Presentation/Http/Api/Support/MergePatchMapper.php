<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Support;

use BackedEnum;
use Spatie\LaravelData\Optional;

/**
 * Folds a list of `[field-enum case, Optional|T|null]` pairs into the two-map
 * shape consumed by partial-update Application commands (see
 * `.claude/rules/application-commands.md`).
 *
 * Three states map onto three structural positions:
 * - `Optional`     → property absent from request body → ignore
 * - `null`         → property explicitly cleared       → append the case to `columnsToClear`
 * - scalar value   → property set                      → write `case->value => value` into `valuesToSet`
 */
final class MergePatchMapper
{
    /**
     * @template TField of BackedEnum
     *
     * @param  list<array{0: TField, 1: Optional|string|int|bool|null}> $properties
     * @return array{0: array<string, scalar>, 1: list<TField>}
     */
    public static function buildMaps(array $properties): array
    {
        $valuesToSet = [];
        $columnsToClear = [];

        foreach ($properties as [$column, $value]) {
            if ($value instanceof Optional) {
                continue;
            }

            if ($value === null) {
                $columnsToClear[] = $column;

                continue;
            }

            $valuesToSet[(string) $column->value] = $value;
        }

        return [$valuesToSet, $columnsToClear];
    }
}
