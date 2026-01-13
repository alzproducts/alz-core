<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderAdminComment Value Object Unit Tests.
 *
 * Tests the OrderAdminComment domain value object construction.
 */
#[CoversClass(OrderAdminComment::class)]
final class OrderAdminCommentTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_admin_comment_with_all_fields(): void
    {
        $createdAt = new DateTimeImmutable('2024-03-15T14:30:00+00:00');

        $comment = new OrderAdminComment(
            externalId: 123,
            content: 'Customer requested express shipping',
            createdAt: $createdAt,
            statusId: 5,
        );

        $this->assertSame(123, $comment->externalId);
        $this->assertSame('Customer requested express shipping', $comment->content);
        $this->assertSame($createdAt, $comment->createdAt);
        $this->assertSame(5, $comment->statusId);
    }

    #[Test]
    public function it_creates_admin_comment_with_null_status_id(): void
    {
        $createdAt = new DateTimeImmutable('2024-03-15T14:30:00+00:00');

        $comment = new OrderAdminComment(
            externalId: 456,
            content: 'General note about order',
            createdAt: $createdAt,
            statusId: null,
        );

        $this->assertSame(456, $comment->externalId);
        $this->assertSame('General note about order', $comment->content);
        $this->assertSame($createdAt, $comment->createdAt);
        $this->assertNull($comment->statusId);
    }

    #[Test]
    public function it_creates_admin_comment_without_status_id_parameter(): void
    {
        $createdAt = new DateTimeImmutable('2024-03-15T14:30:00+00:00');

        $comment = new OrderAdminComment(
            externalId: 789,
            content: 'Default status',
            createdAt: $createdAt,
        );

        $this->assertNull($comment->statusId);
    }

    #[Test]
    public function it_accepts_empty_content(): void
    {
        $createdAt = new DateTimeImmutable('2024-03-15T14:30:00+00:00');

        $comment = new OrderAdminComment(
            externalId: 100,
            content: '',
            createdAt: $createdAt,
        );

        $this->assertSame('', $comment->content);
    }
}
