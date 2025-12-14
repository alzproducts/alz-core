<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Infrastructure\HelpScout\Responses\CustomerWaitingSinceResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CustomerWaitingSinceResponse::class)]
final class CustomerWaitingSinceResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response(): void
    {
        $apiResponse = [
            'time' => '2024-12-10T14:30:00Z',
            'friendly' => '2 hours ago',
        ];

        $response = CustomerWaitingSinceResponse::from($apiResponse);

        $this->assertSame('2024-12-10T14:30:00Z', $response->time);
        $this->assertSame('2 hours ago', $response->friendly);
    }

    #[Test]
    public function it_preserves_iso_timestamp_format(): void
    {
        $apiResponse = [
            'time' => '2024-12-15T09:15:30.123Z',
            'friendly' => '5 minutes ago',
        ];

        $response = CustomerWaitingSinceResponse::from($apiResponse);

        $this->assertSame('2024-12-15T09:15:30.123Z', $response->time);
    }

    #[Test]
    public function it_preserves_friendly_format_variations(): void
    {
        $apiResponse = [
            'time' => '2024-11-01T00:00:00Z',
            'friendly' => '1 month ago',
        ];

        $response = CustomerWaitingSinceResponse::from($apiResponse);

        $this->assertSame('1 month ago', $response->friendly);
    }
}
