<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\Mailbox;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Mailbox::class)]
final class MailboxTest extends TestCase
{
    #[Test]
    public function it_creates_valid_mailbox(): void
    {
        $mailbox = new Mailbox(
            id: 12345,
            name: 'Support',
            email: 'support@example.com',
            slug: 'support',
        );

        $this->assertSame(12345, $mailbox->id);
        $this->assertSame('Support', $mailbox->name);
        $this->assertSame('support@example.com', $mailbox->email);
        $this->assertSame('support', $mailbox->slug);
    }

    #[Test]
    public function it_accepts_mailbox_id_of_one(): void
    {
        $mailbox = new Mailbox(
            id: 1,
            name: 'Test Mailbox',
            email: 'test@example.com',
            slug: 'test',
        );

        $this->assertSame(1, $mailbox->id);
    }

    #[Test]
    public function it_rejects_zero_mailbox_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailbox ID must be positive');

        new Mailbox(
            id: 0,
            name: 'Test',
            email: 'test@example.com',
            slug: 'test',
        );
    }

    #[Test]
    public function it_rejects_negative_mailbox_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailbox ID must be positive');

        new Mailbox(
            id: -1,
            name: 'Test',
            email: 'test@example.com',
            slug: 'test',
        );
    }

    #[Test]
    public function it_rejects_empty_mailbox_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailbox name cannot be empty');

        new Mailbox(
            id: 100,
            name: '',
            email: 'test@example.com',
            slug: 'test',
        );
    }

    #[Test]
    public function it_rejects_empty_mailbox_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailbox slug cannot be empty');

        new Mailbox(
            id: 100,
            name: 'Test',
            email: 'test@example.com',
            slug: '',
        );
    }
}
