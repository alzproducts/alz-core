<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Infrastructure\HelpScout\Responses\TagResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(TagResponse::class)]
final class TagResponseTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response_to_tag_response(): void
    {
        $apiResponse = [
            'id' => 12345,
            'tag' => 'Priority',
            'color' => '#FF0000',
        ];

        $tagResponse = TagResponse::from($apiResponse);

        $this->assertSame(12345, $tagResponse->id);
        $this->assertSame('Priority', $tagResponse->tag);
        $this->assertSame('#FF0000', $tagResponse->color);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_conversation_tag(): void
    {
        $apiResponse = [
            'id' => 67890,
            'tag' => 'VIP Customer',
            'color' => '#00FF00',
        ];

        $tagResponse = TagResponse::from($apiResponse);
        $domainTag = $tagResponse->toDomain();

        $this->assertInstanceOf(ConversationTag::class, $domainTag);
        $this->assertSame(67890, $domainTag->id);
        $this->assertSame('VIP Customer', $domainTag->name);
        $this->assertSame('#00FF00', $domainTag->color);
    }

    #[Test]
    public function it_maps_tag_property_to_name_in_domain(): void
    {
        $tagResponse = TagResponse::from([
            'id' => 100,
            'tag' => 'Escalated',
            'color' => 'blue',
        ]);

        $domainTag = $tagResponse->toDomain();

        // API uses 'tag', domain uses 'name'
        $this->assertSame('Escalated', $domainTag->name);
    }
}
