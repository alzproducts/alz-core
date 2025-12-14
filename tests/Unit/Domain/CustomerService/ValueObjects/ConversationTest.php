<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use App\Domain\CustomerService\ValueObjects\ConversationSnooze;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Conversation::class)]
final class ConversationTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_valid_conversation_with_all_properties(): void
    {
        $createdAt = new DateTimeImmutable('2024-12-01 10:00:00');
        $updatedAt = new DateTimeImmutable('2024-12-10 14:30:00');
        $userUpdatedAt = new DateTimeImmutable('2024-12-10 15:00:00');
        $customerWaitingSince = new DateTimeImmutable('2024-12-10 14:00:00');

        $snooze = new ConversationSnooze(
            snoozedUntil: new DateTimeImmutable('2024-12-15 09:00:00'),
            snoozedByUserId: 999,
        );

        $customer = new ConversationCustomer(
            id: 5001,
            firstName: 'Alice',
            lastName: 'Johnson',
            email: 'alice@example.com',
        );

        $assignee = new ConversationAssignee(
            id: 3001,
            firstName: 'John',
            lastName: 'Doe',
            photoUrl: 'https://example.com/photo.jpg',
        );

        $tags = [
            new ConversationTag(id: 101, name: 'Priority', color: '#FF0000'),
            new ConversationTag(id: 102, name: 'VIP', color: '#00FF00'),
        ];

        $conversation = new Conversation(
            id: 12345,
            number: 1001,
            subject: 'Order inquiry',
            status: 'active',
            mailboxId: 500,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            userUpdatedAt: $userUpdatedAt,
            customerWaitingSince: $customerWaitingSince,
            snooze: $snooze,
            tags: $tags,
            customer: $customer,
            assignee: $assignee,
            mailboxName: 'Support',
        );

        $this->assertSame(12345, $conversation->id);
        $this->assertSame(1001, $conversation->number);
        $this->assertSame('Order inquiry', $conversation->subject);
        $this->assertSame('active', $conversation->status);
        $this->assertSame(500, $conversation->mailboxId);
        $this->assertSame($createdAt, $conversation->createdAt);
        $this->assertSame($updatedAt, $conversation->updatedAt);
        $this->assertSame($userUpdatedAt, $conversation->userUpdatedAt);
        $this->assertSame($customerWaitingSince, $conversation->customerWaitingSince);
        $this->assertSame($snooze, $conversation->snooze);
        $this->assertSame($tags, $conversation->tags);
        $this->assertSame($customer, $conversation->customer);
        $this->assertSame($assignee, $conversation->assignee);
        $this->assertSame('Support', $conversation->mailboxName);
    }

    #[Test]
    public function it_creates_conversation_with_nullable_properties(): void
    {
        $createdAt = new DateTimeImmutable('2024-12-01 10:00:00');

        $conversation = new Conversation(
            id: 12345,
            number: 1001,
            subject: 'Minimal conversation',
            status: 'pending',
            mailboxId: 500,
            createdAt: $createdAt,
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: null,
            snooze: null,
            tags: [],
            customer: null,
            assignee: null,
        );

        $this->assertNull($conversation->updatedAt);
        $this->assertNull($conversation->userUpdatedAt);
        $this->assertNull($conversation->customerWaitingSince);
        $this->assertNull($conversation->snooze);
        $this->assertSame([], $conversation->tags);
        $this->assertNull($conversation->customer);
        $this->assertNull($conversation->assignee);
        $this->assertNull($conversation->mailboxName);
    }

    /*
    |--------------------------------------------------------------------------
    | Boundary Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_conversation_id_of_one(): void
    {
        $conversation = $this->createMinimalConversation(id: 1);

        $this->assertSame(1, $conversation->id);
    }

    #[Test]
    public function it_accepts_conversation_number_of_one(): void
    {
        $conversation = $this->createMinimalConversation(number: 1);

        $this->assertSame(1, $conversation->number);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_zero_conversation_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conversation ID must be positive');

        $this->createMinimalConversation(id: 0);
    }

    #[Test]
    public function it_rejects_negative_conversation_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conversation ID must be positive');

        $this->createMinimalConversation(id: -1);
    }

    #[Test]
    public function it_rejects_zero_conversation_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conversation number must be positive');

        $this->createMinimalConversation(number: 0);
    }

    #[Test]
    public function it_rejects_negative_conversation_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conversation number must be positive');

        $this->createMinimalConversation(number: -1);
    }

    #[Test]
    public function it_rejects_empty_subject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Conversation subject cannot be empty');

        $this->createMinimalConversation(subject: '');
    }

    /*
    |--------------------------------------------------------------------------
    | Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_mailbox_name_returns_new_instance_with_name(): void
    {
        $original = $this->createMinimalConversation();

        $this->assertNull($original->mailboxName);

        $withName = $original->withMailboxName('Support Inbox');

        $this->assertNull($original->mailboxName);
        $this->assertSame('Support Inbox', $withName->mailboxName);
        $this->assertNotSame($original, $withName);
    }

    #[Test]
    public function with_mailbox_name_preserves_all_other_properties(): void
    {
        $createdAt = new DateTimeImmutable('2024-12-01 10:00:00');
        $snooze = new ConversationSnooze(
            snoozedUntil: new DateTimeImmutable('2024-12-15 09:00:00'),
            snoozedByUserId: 999,
        );
        $customer = new ConversationCustomer(id: 5001, firstName: 'Alice', lastName: 'Johnson', email: 'alice@example.com');
        $assignee = new ConversationAssignee(id: 3001, firstName: 'John', lastName: 'Doe', photoUrl: null);
        $tags = [new ConversationTag(id: 101, name: 'Priority', color: '#FF0000')];

        $original = new Conversation(
            id: 12345,
            number: 1001,
            subject: 'Test subject',
            status: 'active',
            mailboxId: 500,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            userUpdatedAt: $createdAt,
            customerWaitingSince: $createdAt,
            snooze: $snooze,
            tags: $tags,
            customer: $customer,
            assignee: $assignee,
        );

        $withName = $original->withMailboxName('New Mailbox');

        $this->assertSame(12345, $withName->id);
        $this->assertSame(1001, $withName->number);
        $this->assertSame('Test subject', $withName->subject);
        $this->assertSame('active', $withName->status);
        $this->assertSame(500, $withName->mailboxId);
        $this->assertSame($createdAt, $withName->createdAt);
        $this->assertSame($createdAt, $withName->updatedAt);
        $this->assertSame($createdAt, $withName->userUpdatedAt);
        $this->assertSame($createdAt, $withName->customerWaitingSince);
        $this->assertSame($snooze, $withName->snooze);
        $this->assertSame($tags, $withName->tags);
        $this->assertSame($customer, $withName->customer);
        $this->assertSame($assignee, $withName->assignee);
        $this->assertSame('New Mailbox', $withName->mailboxName);
    }

    #[Test]
    public function with_mailbox_name_accepts_null(): void
    {
        $original = new Conversation(
            id: 12345,
            number: 1001,
            subject: 'Test',
            status: 'active',
            mailboxId: 500,
            createdAt: new DateTimeImmutable(),
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: null,
            snooze: null,
            tags: [],
            customer: null,
            assignee: null,
            mailboxName: 'Original Name',
        );

        $withNullName = $original->withMailboxName(null);

        $this->assertSame('Original Name', $original->mailboxName);
        $this->assertNull($withNullName->mailboxName);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createMinimalConversation(
        int $id = 12345,
        int $number = 1001,
        string $subject = 'Test subject',
    ): Conversation {
        return new Conversation(
            id: $id,
            number: $number,
            subject: $subject,
            status: 'active',
            mailboxId: 500,
            createdAt: new DateTimeImmutable('2024-12-01 10:00:00'),
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: null,
            snooze: null,
            tags: [],
            customer: null,
            assignee: null,
        );
    }
}
