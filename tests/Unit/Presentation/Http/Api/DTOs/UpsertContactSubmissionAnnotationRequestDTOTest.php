<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Domain\ContactSubmission\Enums\ContactSubmissionAnnotationField;
use App\Domain\ValueObjects\Guid;
use App\Presentation\Http\Api\DTOs\UpsertContactSubmissionAnnotationRequestDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

#[CoversClass(UpsertContactSubmissionAnnotationRequestDTO::class)]
final class UpsertContactSubmissionAnnotationRequestDTOTest extends TestCase
{
    private const string SUBMISSION_ID = '019d9358-01fe-72c9-b123-5f452270d3c1';

    #[Test]
    public function defaults_every_field_to_optional_sentinel(): void
    {
        $dto = new UpsertContactSubmissionAnnotationRequestDTO();

        self::assertInstanceOf(Optional::class, $dto->is_potential_quote);
        self::assertInstanceOf(Optional::class, $dto->notes);
        self::assertInstanceOf(Optional::class, $dto->quoted_at);
    }

    #[Test]
    public function to_command_carries_submission_id_through(): void
    {
        $dto = new UpsertContactSubmissionAnnotationRequestDTO();

        $command = $dto->toCommand(Guid::fromTrusted(self::SUBMISSION_ID));

        self::assertSame(self::SUBMISSION_ID, $command->contactSubmissionId);
    }

    #[Test]
    public function to_command_produces_empty_maps_when_every_field_is_optional(): void
    {
        $dto = new UpsertContactSubmissionAnnotationRequestDTO();

        $command = $dto->toCommand(Guid::fromTrusted(self::SUBMISSION_ID));

        self::assertSame([], $command->valuesToSet);
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function to_command_routes_set_scalars_into_values_to_set(): void
    {
        $dto = new UpsertContactSubmissionAnnotationRequestDTO(
            is_potential_quote: true,
            notes: 'follow up next week',
        );

        $command = $dto->toCommand(Guid::fromTrusted(self::SUBMISSION_ID));

        self::assertSame(
            ['is_potential_quote' => true, 'notes' => 'follow up next week'],
            $command->valuesToSet,
        );
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function to_command_routes_explicit_null_into_columns_to_clear(): void
    {
        $dto = new UpsertContactSubmissionAnnotationRequestDTO(
            is_potential_quote: null,
            notes: null,
            quoted_at: null,
        );

        $command = $dto->toCommand(Guid::fromTrusted(self::SUBMISSION_ID));

        self::assertSame([], $command->valuesToSet);
        self::assertSame(
            [
                ContactSubmissionAnnotationField::IsPotentialQuote,
                ContactSubmissionAnnotationField::Notes,
                ContactSubmissionAnnotationField::QuotedAt,
            ],
            $command->columnsToClear,
        );
    }

    #[Test]
    public function to_command_parses_quoted_at_wire_string_into_iso8601_value(): void
    {
        $dto = new UpsertContactSubmissionAnnotationRequestDTO(
            quoted_at: '2026-05-18T10:00:00+00:00',
        );

        $command = $dto->toCommand(Guid::fromTrusted(self::SUBMISSION_ID));

        self::assertArrayHasKey('quoted_at', $command->valuesToSet);
        self::assertSame('2026-05-18T10:00:00+00:00', $command->valuesToSet['quoted_at']);
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function to_command_keeps_set_and_clear_branches_independent_when_mixed(): void
    {
        $dto = new UpsertContactSubmissionAnnotationRequestDTO(
            is_potential_quote: true,
            notes: null,
        );

        $command = $dto->toCommand(Guid::fromTrusted(self::SUBMISSION_ID));

        self::assertSame(['is_potential_quote' => true], $command->valuesToSet);
        self::assertSame([ContactSubmissionAnnotationField::Notes], $command->columnsToClear);
    }
}
