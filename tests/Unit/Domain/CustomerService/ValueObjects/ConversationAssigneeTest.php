<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationAssignee::class)]
final class ConversationAssigneeTest extends TestCase
{
    #[Test]
    public function it_creates_valid_assignee_with_photo(): void
    {
        $assignee = new ConversationAssignee(
            id: 12345,
            firstName: 'John',
            lastName: 'Doe',
            photoUrl: 'https://example.com/photo.jpg',
        );

        $this->assertSame(12345, $assignee->id);
        $this->assertSame('John', $assignee->firstName);
        $this->assertSame('Doe', $assignee->lastName);
        $this->assertSame('https://example.com/photo.jpg', $assignee->photoUrl);
    }

    #[Test]
    public function it_creates_valid_assignee_without_photo(): void
    {
        $assignee = new ConversationAssignee(
            id: 67890,
            firstName: 'Jane',
            lastName: 'Smith',
            photoUrl: null,
        );

        $this->assertSame(67890, $assignee->id);
        $this->assertSame('Jane', $assignee->firstName);
        $this->assertSame('Smith', $assignee->lastName);
        $this->assertNull($assignee->photoUrl);
    }

    #[Test]
    public function it_accepts_assignee_id_of_one(): void
    {
        $assignee = new ConversationAssignee(
            id: 1,
            firstName: 'Test',
            lastName: 'User',
            photoUrl: null,
        );

        $this->assertSame(1, $assignee->id);
    }

    #[Test]
    public function it_rejects_zero_assignee_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Assignee ID must be positive');

        new ConversationAssignee(
            id: 0,
            firstName: 'Test',
            lastName: 'User',
            photoUrl: null,
        );
    }

    #[Test]
    public function it_rejects_negative_assignee_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Assignee ID must be positive');

        new ConversationAssignee(
            id: -1,
            firstName: 'Test',
            lastName: 'User',
            photoUrl: null,
        );
    }
}
