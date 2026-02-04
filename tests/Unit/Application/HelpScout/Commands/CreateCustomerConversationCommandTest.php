<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Commands;

use App\Application\HelpScout\Commands\CreateCustomerConversationCommand;
use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\CustomerService\ValueObjects\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CreateCustomerConversationCommand Unit Tests.
 *
 * Tests normalization logic applied to customer-initiated conversation data.
 * Email lowercase and field trimming ensure consistent HelpScout customer matching.
 */
#[CoversClass(CreateCustomerConversationCommand::class)]
final class CreateCustomerConversationCommandTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Email Normalization Tests
    |--------------------------------------------------------------------------
    | Email is lowercased and trimmed for consistent customer matching.
    */

    #[Test]
    public function it_lowercases_email(): void
    {
        $command = $this->createCommand(email: 'Customer@Example.COM');

        self::assertSame('customer@example.com', $command->email);
    }

    #[Test]
    public function it_trims_email(): void
    {
        $command = $this->createCommand(email: '  customer@example.com  ');

        self::assertSame('customer@example.com', $command->email);
    }

    #[Test]
    public function it_lowercases_and_trims_email(): void
    {
        $command = $this->createCommand(email: '  CUSTOMER@EXAMPLE.COM  ');

        self::assertSame('customer@example.com', $command->email);
    }

    /*
    |--------------------------------------------------------------------------
    | Name Normalization Tests
    |--------------------------------------------------------------------------
    | Name is trimmed but case is preserved.
    */

    #[Test]
    public function it_trims_name(): void
    {
        $command = $this->createCommand(name: '  John Smith  ');

        self::assertSame('John Smith', $command->name);
    }

    #[Test]
    public function it_preserves_name_case(): void
    {
        $command = $this->createCommand(name: 'John McDonald');

        self::assertSame('John McDonald', $command->name);
    }

    /*
    |--------------------------------------------------------------------------
    | Subject Normalization Tests
    |--------------------------------------------------------------------------
    | Subject is trimmed but case is preserved.
    */

    #[Test]
    public function it_trims_subject(): void
    {
        $command = $this->createCommand(subject: '  Order Inquiry  ');

        self::assertSame('Order Inquiry', $command->subject);
    }

    #[Test]
    public function it_preserves_subject_case(): void
    {
        $command = $this->createCommand(subject: 'URGENT: Order Issue');

        self::assertSame('URGENT: Order Issue', $command->subject);
    }

    /*
    |--------------------------------------------------------------------------
    | Phone Normalization Tests
    |--------------------------------------------------------------------------
    | Phone is trimmed if provided, null passthrough if null.
    */

    #[Test]
    public function it_trims_phone(): void
    {
        $command = $this->createCommand(phone: '  +44 7911 123456  ');

        self::assertSame('+44 7911 123456', $command->phone);
    }

    #[Test]
    public function it_preserves_null_phone(): void
    {
        $command = $this->createCommand(phone: null);

        self::assertNull($command->phone);
    }

    /*
    |--------------------------------------------------------------------------
    | Body and Static Field Tests
    |--------------------------------------------------------------------------
    | Body and enum fields are passed through without modification.
    */

    #[Test]
    public function it_preserves_body_as_is(): void
    {
        $body = "  This is my message.\n\nWith multiple lines.  ";
        $command = $this->createCommand(body: $body);

        self::assertSame($body, $command->body);
    }

    #[Test]
    public function it_preserves_mailbox(): void
    {
        $command = $this->createCommand(mailbox: Mailbox::PurchaseOrders);

        self::assertSame(Mailbox::PurchaseOrders, $command->mailbox);
    }

    #[Test]
    public function it_preserves_type(): void
    {
        $command = $this->createCommand(type: ConversationType::Phone);

        self::assertSame(ConversationType::Phone, $command->type);
    }

    #[Test]
    public function it_preserves_status(): void
    {
        $command = $this->createCommand(status: ConversationStatus::Pending);

        self::assertSame(ConversationStatus::Pending, $command->status);
    }

    #[Test]
    public function it_preserves_tags(): void
    {
        $tags = [new Tag('web-form'), new Tag('urgent')];
        $command = $this->createCommand(tags: $tags);

        self::assertCount(2, $command->tags);
        self::assertSame('web-form', $command->tags[0]->name);
        self::assertSame('urgent', $command->tags[1]->name);
    }

    #[Test]
    public function it_defaults_to_empty_tags(): void
    {
        $command = new CreateCustomerConversationCommand(
            email: 'test@example.com',
            name: 'Test User',
            subject: 'Test Subject',
            body: 'Test body',
            mailbox: Mailbox::Support,
            type: ConversationType::Email,
            status: ConversationStatus::Active,
        );

        self::assertSame([], $command->tags);
    }

    /*
    |--------------------------------------------------------------------------
    | Unicode Tests
    |--------------------------------------------------------------------------
    | Ensure multibyte string functions work correctly.
    */

    #[Test]
    public function it_handles_unicode_email(): void
    {
        $command = $this->createCommand(email: '  Müller@example.com  ');

        self::assertSame('müller@example.com', $command->email);
    }

    #[Test]
    public function it_handles_unicode_name(): void
    {
        $command = $this->createCommand(name: '  François Müller  ');

        self::assertSame('François Müller', $command->name);
    }

    #[Test]
    public function it_handles_unicode_subject(): void
    {
        $command = $this->createCommand(subject: '  Ré: Commande nº 12345  ');

        self::assertSame('Ré: Commande nº 12345', $command->subject);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<Tag> $tags
     */
    private function createCommand(
        string $email = 'test@example.com',
        string $name = 'Test User',
        string $subject = 'Test Subject',
        string $body = 'Test body',
        Mailbox $mailbox = Mailbox::Support,
        ConversationType $type = ConversationType::Email,
        ConversationStatus $status = ConversationStatus::Active,
        ?string $phone = null,
        array $tags = [],
    ): CreateCustomerConversationCommand {
        return new CreateCustomerConversationCommand(
            email: $email,
            name: $name,
            subject: $subject,
            body: $body,
            mailbox: $mailbox,
            type: $type,
            status: $status,
            phone: $phone,
            tags: $tags,
        );
    }
}
