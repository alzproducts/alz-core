<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\ContactSubmission\Queries\ContactSubmissionListQueryParams;
use App\Domain\ContactSubmission\Enums\ContactSubmissionFilterField;
use App\Domain\ContactSubmission\Enums\ConversionStatus;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use LogicException;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Request validation for GET /api/contact-submissions.
 *
 * `date_from` and `date_to` accept `Y-m-d`. The DTO translates them into the half-open
 * `[from-midnight, to-midnight + 1 day)` interval used by the query repository so the
 * filter is inclusive end-of-day without a separate "include end" flag.
 */
final class ListContactSubmissionsRequestDTO extends Data
{
    private const string BOOL_RULE = 'in:true,false,1,0';

    public function __construct(
        #[IntegerType, Min(1), Max(100)]
        public readonly int $per_page = 50,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
        #[Nullable, StringType]
        public readonly ?string $has_gclid = null,
        #[Nullable, StringType]
        public readonly ?string $is_potential_quote = null,
        #[Nullable, StringType]
        public readonly ?string $date_from = null,
        #[Nullable, StringType]
        public readonly ?string $date_to = null,
        #[Nullable, StringType]
        public readonly ?string $conversion_status = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'has_gclid' => ['nullable', 'string', self::BOOL_RULE],
            'is_potential_quote' => ['nullable', 'string', self::BOOL_RULE],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'conversion_status' => [
                'nullable',
                'string',
                'in:' . \implode(',', \array_column(ConversionStatus::cases(), 'value')),
            ],
        ];
    }

    public function toQuery(): ContactSubmissionListQueryParams
    {
        return new ContactSubmissionListQueryParams(
            pagination: PageRequest::from(page: $this->page, perPage: $this->per_page),
            filters: $this->buildFilters(),
        );
    }

    /**
     * @return array<value-of<ContactSubmissionFilterField>, mixed>
     */
    private function buildFilters(): array
    {
        return \array_filter(
            [
                ContactSubmissionFilterField::HasGclid->value => self::parseBoolFilter($this->has_gclid),
                ContactSubmissionFilterField::IsPotentialQuote->value => self::parseBoolFilter($this->is_potential_quote),
                ContactSubmissionFilterField::DateFrom->value => self::parseDateFilter($this->date_from, addDay: false),
                ContactSubmissionFilterField::DateTo->value => self::parseDateFilter($this->date_to, addDay: true),
                ContactSubmissionFilterField::ConversionStatus->value => self::parseConversionStatus($this->conversion_status),
            ],
            static fn(mixed $v): bool => $v !== null,
        );
    }

    /**
     * Map a query-string boolean filter (`true`/`false`/`1`/`0`) to `?bool`.
     *
     * The `BOOL_RULE` validation already restricts inputs to the accepted forms, so reaching
     * an unrecognised value means the rule and this parser have drifted apart.
     */
    private static function parseBoolFilter(?string $value): ?bool
    {
        return match ($value) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw new LogicException('Bool filter passed validation but did not match BOOL_RULE: ' . $value),
        };
    }

    /**
     * Resolve the `conversion_status` query parameter to its enum case.
     *
     * Returns `null` when no filter was supplied. The string has already passed `in:`
     * validation, so failure to resolve is a programming error, not user input.
     */
    private static function parseConversionStatus(?string $value): ?ConversionStatus
    {
        if ($value === null) {
            return null;
        }

        $status = ConversionStatus::tryFrom($value);
        if ($status === null) {
            throw new LogicException('conversion_status passed validation but did not resolve to an enum case: ' . $value);
        }

        return $status;
    }

    /**
     * Parse a `Y-m-d` filter value to a half-open interval bound.
     *
     * Returns `null` when no filter was supplied. When supplied, the input has already passed
     * `date_format:Y-m-d` validation, so Carbon must produce a real instance — the assertion is
     * a programming-error guard, not a runtime branch.
     */
    private static function parseDateFilter(?string $value, bool $addDay): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value);
        if (! $date instanceof CarbonImmutable) {
            throw new LogicException('Y-m-d-validated value did not parse: ' . $value);
        }

        $bound = $date->startOfDay();

        return ($addDay ? $bound->addDay() : $bound)->toDateTimeImmutable();
    }
}
