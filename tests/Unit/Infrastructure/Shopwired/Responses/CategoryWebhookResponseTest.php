<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Infrastructure\Shopwired\Responses\CategoryWebhookResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

#[CoversClass(CategoryWebhookResponse::class)]
final class CategoryWebhookResponseTest extends TestCase
{
    // ========================================================================
    // presentEmbeds()
    // ========================================================================

    #[Test]
    public function it_returns_empty_embeds_when_none_present(): void
    {
        $response = CategoryWebhookResponse::from(self::corePayload());

        self::assertSame([], $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_parents_when_present(): void
    {
        $payload = self::corePayload();
        $payload['parents'] = [['id' => 10, 'created_at' => '2025-01-01', 'title' => 'Parent', 'description' => null, 'description2' => null, 'slug' => 'parent', 'url' => '/parent', 'active' => true, 'featured' => false, 'trade_only' => false, 'sort_order' => 1, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null, 'meta_no_index' => false]];

        $response = CategoryWebhookResponse::from($payload);

        self::assertContains('parents', $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_custom_fields_when_present(): void
    {
        $payload = self::corePayload();
        $payload['custom_fields'] = ['colour' => 'Blue'];

        $response = CategoryWebhookResponse::from($payload);

        self::assertContains('custom_fields', $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_all_embeds_when_all_present(): void
    {
        $payload = self::corePayload();
        $payload['parents'] = [];
        $payload['custom_fields'] = [];

        $response = CategoryWebhookResponse::from($payload);

        self::assertSame(['parents', 'custom_fields'], $response->presentEmbeds());
    }

    #[Test]
    public function it_keeps_embed_fields_as_optional_when_absent(): void
    {
        $response = CategoryWebhookResponse::from(self::corePayload());

        self::assertInstanceOf(Optional::class, $response->parents);
        self::assertInstanceOf(Optional::class, $response->customFields);
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    /**
     * @return array<string, mixed>
     */
    private static function corePayload(): array
    {
        return [
            'id' => 42,
            'created_at' => '2025-01-01T00:00:00Z',
            'title' => 'Test Category',
            'description' => null,
            'description2' => null,
            'slug' => 'test-category',
            'url' => '/test-category',
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => 1,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'meta_no_index' => false,
        ];
    }
}
