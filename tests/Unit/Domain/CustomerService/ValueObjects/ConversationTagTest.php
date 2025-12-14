<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\ConversationTag;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationTag::class)]
final class ConversationTagTest extends TestCase
{
    #[Test]
    public function it_creates_valid_tag_with_color(): void
    {
        $tag = new ConversationTag(
            id: 100,
            name: 'Priority',
            color: '#FF0000',
        );

        $this->assertSame(100, $tag->id);
        $this->assertSame('Priority', $tag->name);
        $this->assertSame('#FF0000', $tag->color);
    }

    #[Test]
    public function it_creates_valid_tag_without_color(): void
    {
        $tag = new ConversationTag(
            id: 200,
            name: 'Urgent',
            color: null,
        );

        $this->assertSame(200, $tag->id);
        $this->assertSame('Urgent', $tag->name);
        $this->assertNull($tag->color);
    }

    #[Test]
    public function it_accepts_tag_id_of_one(): void
    {
        $tag = new ConversationTag(
            id: 1,
            name: 'Boundary Test',
            color: null,
        );

        $this->assertSame(1, $tag->id);
    }

    #[Test]
    public function it_rejects_zero_tag_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag ID must be positive');

        new ConversationTag(
            id: 0,
            name: 'Test',
            color: null,
        );
    }

    #[Test]
    public function it_rejects_negative_tag_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag ID must be positive');

        new ConversationTag(
            id: -1,
            name: 'Test',
            color: null,
        );
    }

    #[Test]
    public function it_rejects_empty_tag_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag name cannot be empty');

        new ConversationTag(
            id: 100,
            name: '',
            color: null,
        );
    }
}
