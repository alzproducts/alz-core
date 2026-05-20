<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Presentation\Http\Api\DTOs\LeadConversionRequestDTO;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(LeadConversionRequestDTO::class)]
final class LeadConversionRequestDTOTest extends TestCase
{
    private const string VALID_UUID = 'd9dd22a9-c3ab-413b-8a93-25b462231a98';

    #[Test]
    public function maps_submission_id_and_is_potential_quote_from_snake_case_payload(): void
    {
        $dto = LeadConversionRequestDTO::validateAndCreate([
            'submission_id' => self::VALID_UUID,
            'is_potential_quote' => true,
        ]);

        self::assertSame(self::VALID_UUID, $dto->submissionId);
        self::assertTrue($dto->isPotentialQuote);
    }

    #[Test]
    public function is_potential_quote_accepts_false_value(): void
    {
        $dto = LeadConversionRequestDTO::validateAndCreate([
            'submission_id' => self::VALID_UUID,
            'is_potential_quote' => false,
        ]);

        self::assertFalse($dto->isPotentialQuote);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'missing is_potential_quote' => [['submission_id' => self::VALID_UUID]];
        yield 'missing submission_id' => [['is_potential_quote' => true]];
        yield 'is_potential_quote as string' => [[
            'submission_id' => self::VALID_UUID,
            'is_potential_quote' => 'yes',
        ]];
        yield 'submission_id not uuid' => [[
            'submission_id' => 'not-a-uuid',
            'is_potential_quote' => true,
        ]];
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function validation_rejects_malformed_payload(array $payload): void
    {
        $this->expectException(ValidationException::class);

        LeadConversionRequestDTO::validateAndCreate($payload);
    }
}
