<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Domain\ContactSubmission\Enums\ConversionStatus;
use App\Presentation\Http\Api\DTOs\ListContactSubmissionsRequestDTO;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ListContactSubmissionsRequestDTO::class)]
final class ListContactSubmissionsRequestDTOTest extends TestCase
{
    #[Test]
    public function defaults_produce_first_page_of_fifty_with_no_filters(): void
    {
        $dto = new ListContactSubmissionsRequestDTO();
        $query = $dto->toQuery();

        self::assertSame(1, $query->pagination->page);
        self::assertSame(50, $query->pagination->perPage);
        self::assertNull($query->filters->hasGclid);
        self::assertNull($query->filters->isPotentialQuote);
        self::assertNull($query->filters->dateFrom);
        self::assertNull($query->filters->dateTo);
        self::assertNull($query->filters->conversionStatus);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: ?bool}>
     */
    public static function boolFilterCases(): iterable
    {
        yield 'null stays null' => [null, null];
        yield 'string "true" → true' => ['true', true];
        yield 'string "1" → true' => ['1', true];
        yield 'string "false" → false' => ['false', false];
        yield 'string "0" → false' => ['0', false];
    }

    #[Test]
    #[DataProvider('boolFilterCases')]
    public function parses_has_gclid_query_string_form_to_nullable_bool(?string $input, ?bool $expected): void
    {
        $dto = new ListContactSubmissionsRequestDTO(has_gclid: $input);

        self::assertSame($expected, $dto->toQuery()->filters->hasGclid);
    }

    #[Test]
    #[DataProvider('boolFilterCases')]
    public function parses_is_potential_quote_query_string_form_to_nullable_bool(?string $input, ?bool $expected): void
    {
        $dto = new ListContactSubmissionsRequestDTO(is_potential_quote: $input);

        self::assertSame($expected, $dto->toQuery()->filters->isPotentialQuote);
    }

    #[Test]
    public function date_from_is_translated_to_start_of_day_in_utc(): void
    {
        $dto = new ListContactSubmissionsRequestDTO(date_from: '2026-04-16');
        $dateFrom = $dto->toQuery()->filters->dateFrom;

        self::assertInstanceOf(DateTimeImmutable::class, $dateFrom);
        self::assertSame('2026-04-16T00:00:00+00:00', $dateFrom->format(\DATE_ATOM));
    }

    #[Test]
    public function date_to_is_translated_to_next_day_midnight_to_form_half_open_interval(): void
    {
        $dto = new ListContactSubmissionsRequestDTO(date_to: '2026-04-16');
        $dateTo = $dto->toQuery()->filters->dateTo;

        self::assertInstanceOf(DateTimeImmutable::class, $dateTo);
        self::assertSame('2026-04-17T00:00:00+00:00', $dateTo->format(\DATE_ATOM));
    }

    #[Test]
    public function single_day_date_range_produces_24h_window_via_half_open_interval(): void
    {
        $dto = new ListContactSubmissionsRequestDTO(date_from: '2026-04-16', date_to: '2026-04-16');
        $filters = $dto->toQuery()->filters;

        self::assertInstanceOf(DateTimeImmutable::class, $filters->dateFrom);
        self::assertInstanceOf(DateTimeImmutable::class, $filters->dateTo);
        self::assertSame(86_400, $filters->dateTo->getTimestamp() - $filters->dateFrom->getTimestamp());
    }

    #[Test]
    public function conversion_status_resolves_to_enum_case(): void
    {
        $dto = new ListContactSubmissionsRequestDTO(conversion_status: 'lead_pending');

        self::assertSame(ConversionStatus::LeadPending, $dto->toQuery()->filters->conversionStatus);
    }

    #[Test]
    public function conversion_status_null_stays_null(): void
    {
        $dto = new ListContactSubmissionsRequestDTO(conversion_status: null);

        self::assertNull($dto->toQuery()->filters->conversionStatus);
    }

    #[Test]
    public function pagination_uses_explicit_per_page_and_page(): void
    {
        $dto = new ListContactSubmissionsRequestDTO(per_page: 25, page: 3);
        $pagination = $dto->toQuery()->pagination;

        self::assertSame(3, $pagination->page);
        self::assertSame(25, $pagination->perPage);
    }
}
