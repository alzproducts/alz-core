<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\Mailbox;
use App\Infrastructure\HelpScout\Responses\MailboxResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MailboxResponse::class)]
final class MailboxResponseTest extends TestCase
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
            'id' => 12345,
            'name' => 'Support Inbox',
            'email' => 'support@example.com',
            'slug' => 'support',
            'createdAt' => '2024-01-01T00:00:00Z',
            'updatedAt' => '2024-12-01T12:00:00Z',
        ];

        $mailboxResponse = MailboxResponse::from($apiResponse);

        $this->assertSame(12345, $mailboxResponse->id);
        $this->assertSame('Support Inbox', $mailboxResponse->name);
        $this->assertSame('support@example.com', $mailboxResponse->email);
        $this->assertSame('support', $mailboxResponse->slug);
        $this->assertSame('2024-01-01T00:00:00Z', $mailboxResponse->createdAt);
        $this->assertSame('2024-12-01T12:00:00Z', $mailboxResponse->updatedAt);
    }

    #[Test]
    public function it_parses_api_response_with_nullable_fields(): void
    {
        $apiResponse = [
            'id' => 67890,
            'name' => 'Sales',
            'email' => 'sales@example.com',
            'slug' => null,
            'createdAt' => null,
            'updatedAt' => null,
        ];

        $mailboxResponse = MailboxResponse::from($apiResponse);

        $this->assertSame(67890, $mailboxResponse->id);
        $this->assertNull($mailboxResponse->slug);
        $this->assertNull($mailboxResponse->createdAt);
        $this->assertNull($mailboxResponse->updatedAt);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_mailbox(): void
    {
        $apiResponse = [
            'id' => 12345,
            'name' => 'Customer Support',
            'email' => 'help@example.com',
            'slug' => 'customer-support',
            'createdAt' => '2024-01-01T00:00:00Z',
            'updatedAt' => '2024-12-01T12:00:00Z',
        ];

        $mailboxResponse = MailboxResponse::from($apiResponse);
        $domainMailbox = $mailboxResponse->toDomain();

        $this->assertInstanceOf(Mailbox::class, $domainMailbox);
        $this->assertSame(12345, $domainMailbox->id);
        $this->assertSame('Customer Support', $domainMailbox->name);
        $this->assertSame('help@example.com', $domainMailbox->email);
        $this->assertSame('customer-support', $domainMailbox->slug);
    }

    #[Test]
    public function it_throws_when_null_slug_converts_to_empty_string(): void
    {
        $apiResponse = [
            'id' => 99999,
            'name' => 'Legacy Mailbox',
            'email' => 'legacy@example.com',
            'slug' => null,
            'createdAt' => null,
            'updatedAt' => null,
        ];

        $mailboxResponse = MailboxResponse::from($apiResponse);

        // Domain requires non-empty slug - null converts to '' which fails validation
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailbox slug cannot be empty');

        $mailboxResponse->toDomain();
    }
}
