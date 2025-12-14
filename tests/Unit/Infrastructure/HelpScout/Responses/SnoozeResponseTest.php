<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\HelpScout\Responses\SnoozeResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SnoozeResponse::class)]
final class SnoozeResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response_with_all_fields(): void
    {
        $apiResponse = [
            'snoozedBy' => 12345,
            'snoozedUntil' => '2024-12-20T10:00:00Z',
            'unsnoozeOnCustomerReply' => true,
        ];

        $snoozeResponse = SnoozeResponse::from($apiResponse);

        $this->assertSame(12345, $snoozeResponse->snoozedBy);
        $this->assertSame('2024-12-20T10:00:00Z', $snoozeResponse->snoozedUntil);
        $this->assertTrue($snoozeResponse->unsnoozeOnCustomerReply);
    }

    #[Test]
    public function it_parses_api_response_with_nullable_fields(): void
    {
        $apiResponse = [
            'snoozedBy' => null,
            'snoozedUntil' => null,
            'unsnoozeOnCustomerReply' => null,
        ];

        $snoozeResponse = SnoozeResponse::from($apiResponse);

        $this->assertNull($snoozeResponse->snoozedBy);
        $this->assertNull($snoozeResponse->snoozedUntil);
        $this->assertNull($snoozeResponse->unsnoozeOnCustomerReply);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_conversation_snooze(): void
    {
        $apiResponse = [
            'snoozedBy' => 67890,
            'snoozedUntil' => '2024-12-25T14:30:00Z',
            'unsnoozeOnCustomerReply' => false,
        ];

        $snoozeResponse = SnoozeResponse::from($apiResponse);
        $domainSnooze = $snoozeResponse->toDomain();

        $this->assertInstanceOf(ConversationSnooze::class, $domainSnooze);
        $this->assertSame('2024-12-25 14:30:00', $domainSnooze->snoozedUntil->format('Y-m-d H:i:s'));
        $this->assertSame(67890, $domainSnooze->snoozedByUserId);
    }

    #[Test]
    public function it_returns_null_when_snoozed_until_is_null(): void
    {
        $apiResponse = [
            'snoozedBy' => 12345,
            'snoozedUntil' => null,
            'unsnoozeOnCustomerReply' => true,
        ];

        $snoozeResponse = SnoozeResponse::from($apiResponse);
        $domainSnooze = $snoozeResponse->toDomain();

        $this->assertNull($domainSnooze);
    }

    #[Test]
    public function it_preserves_snoozed_by_user_id_in_domain(): void
    {
        $apiResponse = [
            'snoozedBy' => 99999,
            'snoozedUntil' => '2024-12-30T08:00:00Z',
            'unsnoozeOnCustomerReply' => false,
        ];

        $snoozeResponse = SnoozeResponse::from($apiResponse);
        $domainSnooze = $snoozeResponse->toDomain();

        $this->assertSame(99999, $domainSnooze?->snoozedByUserId);
    }

    #[Test]
    public function it_handles_null_snoozed_by_in_domain(): void
    {
        $apiResponse = [
            'snoozedBy' => null,
            'snoozedUntil' => '2024-12-28T16:00:00Z',
            'unsnoozeOnCustomerReply' => true,
        ];

        $snoozeResponse = SnoozeResponse::from($apiResponse);
        $domainSnooze = $snoozeResponse->toDomain();

        $this->assertInstanceOf(ConversationSnooze::class, $domainSnooze);
        $this->assertNull($domainSnooze->snoozedByUserId);
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid Date Format Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_invalid_api_response_exception_for_malformed_date(): void
    {
        $apiResponse = [
            'snoozedBy' => 12345,
            'snoozedUntil' => 'invalid-date-format',
            'unsnoozeOnCustomerReply' => false,
        ];

        $snoozeResponse = SnoozeResponse::from($apiResponse);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Invalid snoozedUntil date format: invalid-date-format');

        $snoozeResponse->toDomain();
    }
}
