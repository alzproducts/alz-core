<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Infrastructure\Shopwired\Responses\BrandWebhookResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

#[CoversClass(BrandWebhookResponse::class)]
final class BrandWebhookResponseTest extends TestCase
{
    // ========================================================================
    // presentEmbeds()
    // ========================================================================

    #[Test]
    public function it_returns_empty_embeds_when_none_present(): void
    {
        $response = BrandWebhookResponse::from(self::corePayload());

        self::assertSame([], $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_custom_fields_when_present(): void
    {
        $payload = self::corePayload();
        $payload['custom_fields'] = ['colour' => 'Blue'];

        $response = BrandWebhookResponse::from($payload);

        self::assertSame(['custom_fields'], $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_empty_custom_fields_as_present(): void
    {
        $payload = self::corePayload();
        $payload['custom_fields'] = [];

        $response = BrandWebhookResponse::from($payload);

        self::assertSame(['custom_fields'], $response->presentEmbeds());
    }

    #[Test]
    public function it_keeps_custom_fields_as_optional_when_absent(): void
    {
        $response = BrandWebhookResponse::from(self::corePayload());

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
            'id' => 7,
            'created_at' => '2025-01-01T00:00:00Z',
            'title' => 'Test Brand',
            'description' => null,
            'slug' => 'test-brand',
            'url' => '/brands/test-brand',
            'active' => true,
            'featured' => false,
            'sort_order' => 1,
            'meta_title' => null,
            'meta_keywords' => null,
            'meta_description' => null,
        ];
    }
}
