<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Commands;

use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use Webmozart\Assert\Assert;

/**
 * Partial change set for `marketing.contact_submission_annotations`.
 *
 * Three states encoded across two structural positions (no null overloading):
 * - column key in {@see $valuesToSet}    → write that value to the column
 * - case in {@see $columnsToClear}       → write NULL to the column
 * - column in neither                    → leave the column untouched
 */
final readonly class UpsertAnnotationCommand
{
    /**
     * @param array<string, scalar>                       $valuesToSet    keyed by DB column name
     * @param list<ContactSubmissionAnnotationField>      $columnsToClear
     */
    public function __construct(
        public string $contactSubmissionId,
        public array $valuesToSet,
        public array $columnsToClear,
    ) {
        Assert::uuid($contactSubmissionId, 'contactSubmissionId must be a UUID');

        $validColumns = \array_map(
            static fn(ContactSubmissionAnnotationField $c): string => $c->value,
            ContactSubmissionAnnotationField::cases(),
        );

        Assert::allOneOf(
            \array_keys($valuesToSet),
            $validColumns,
            'Unknown column in valuesToSet: %s.',
        );

        $clearedColumnNames = \array_map(
            static fn(ContactSubmissionAnnotationField $c): string => $c->value,
            $columnsToClear,
        );

        Assert::isEmpty(
            \array_intersect(\array_keys($valuesToSet), $clearedColumnNames),
            'A column cannot appear in both valuesToSet and columnsToClear.',
        );

        Assert::allTrue(
            \array_map(
                static fn(ContactSubmissionAnnotationField $c): bool => $c->isClearable(),
                $columnsToClear,
            ),
            'Only clearable columns may appear in columnsToClear.',
        );
    }
}
