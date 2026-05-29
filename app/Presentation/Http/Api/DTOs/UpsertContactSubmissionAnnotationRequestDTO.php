<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\ContactSubmission\Commands\UpsertAnnotationCommand;
use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\ValueObjects\Guid;
use App\Presentation\Http\Api\Support\MergePatchMapper;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * PUT body for `marketing.potential_conversion_annotations`.
 *
 * Partial-update semantics via {@see Optional}:
 *  - field absent from body → property is `Optional`  → no change
 *  - field sent as `null`   → property is `null`      → clear the column
 *  - field sent with value  → property is the value   → set the column
 *
 * `quoted_at` is parsed to a UTC timestamp before being folded into `$valuesToSet` so the
 * Application command never has to deal with wire-format strings.
 */
final class UpsertContactSubmissionAnnotationRequestDTO extends Data
{
    public function __construct(
        public readonly Optional|bool|null $is_potential_quote = new Optional(),
        #[Max(5000)]
        public readonly Optional|string|null $notes = new Optional(),
        public readonly Optional|string|null $quoted_at = new Optional(),
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'is_potential_quote' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'quoted_at' => ['nullable', 'date'],
        ];
    }

    public function toCommand(Guid $sourceId): UpsertAnnotationCommand
    {
        $quotedAtValue = $this->quoted_at;
        if (\is_string($quotedAtValue)) {
            $quotedAtValue = CarbonImmutable::parse($quotedAtValue)->toIso8601String();
        }

        [$valuesToSet, $columnsToClear] = MergePatchMapper::buildMaps([
            [ContactSubmissionAnnotationField::IsPotentialQuote, $this->is_potential_quote],
            [ContactSubmissionAnnotationField::Notes, $this->notes],
            [ContactSubmissionAnnotationField::QuotedAt, $quotedAtValue],
        ]);

        return new UpsertAnnotationCommand(
            sourceId: $sourceId->value,
            valuesToSet: $valuesToSet,
            columnsToClear: $columnsToClear,
        );
    }
}
