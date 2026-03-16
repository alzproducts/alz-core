<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Parsers;

use App\Application\Shopwired\DTOs\WebhookOrderRefundResultDTO;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Shopwired\Parsers\ShopwiredOrderWebhookParser;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ShopwiredOrderWebhookParser Unit Tests.
 *
 * Tests parseOrderRefund() and parseRefundExternalId() payload parsing,
 * nested key extraction, and exception handling for malformed payloads.
 */
#[CoversClass(ShopwiredOrderWebhookParser::class)]
final class ShopwiredOrderWebhookParserTest extends TestCase
{
    private ShopwiredOrderWebhookParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ShopwiredOrderWebhookParser();
    }

    // ========================================================================
    // parseOrderRefund — Happy Path
    // ========================================================================

    #[Test]
    public function it_parses_a_valid_refund_webhook_payload(): void
    {
        $data = ['object' => self::validRefundPayload()];

        $result = $this->parser->parseOrderRefund($data);

        self::assertInstanceOf(WebhookOrderRefundResultDTO::class, $result);
        self::assertSame(11479559, $result->orderId->value);
        self::assertInstanceOf(OrderRefund::class, $result->refund);
        self::assertSame(128409325, $result->refund->externalId);
        self::assertSame('Date:16-03-2026>14.95*DOR-SSD1*Not big enough>', $result->refund->name);
        self::assertSame(14.95, $result->refund->value);
        self::assertEquals(new DateTimeImmutable('Mon, 16 Mar 2026 10:35:33 +0000'), $result->refund->createdAt);
    }

    // ========================================================================
    // parseOrderRefund — Error Paths
    // ========================================================================

    #[Test]
    public function it_throws_when_object_key_is_missing(): void
    {
        $this->expectException(InvalidApiResponseException::class);

        $this->parser->parseOrderRefund(['event' => 'order.refund.created']);
    }

    #[Test]
    public function it_throws_on_missing_required_fields_in_object(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('ShopWired order refund webhook payload type mismatch', Mockery::type('array'));

        $this->expectException(InvalidApiResponseException::class);

        $this->parser->parseOrderRefund(['object' => ['id' => 123]]);
    }

    // ========================================================================
    // parseRefundExternalId — Happy Path
    // ========================================================================

    #[Test]
    public function it_parses_a_valid_refund_external_id(): void
    {
        $data = ['object' => ['id' => 128409325]];

        $result = $this->parser->parseRefundExternalId($data);

        self::assertInstanceOf(IntId::class, $result);
        self::assertSame(128409325, $result->value);
    }

    // ========================================================================
    // parseRefundExternalId — Error Paths
    // ========================================================================

    #[Test]
    public function it_throws_when_object_key_is_missing_in_refund_delete(): void
    {
        $this->expectException(InvalidApiResponseException::class);

        $this->parser->parseRefundExternalId(['event' => 'order.refund.deleted']);
    }

    #[Test]
    public function it_throws_when_id_is_missing_in_refund_delete_object(): void
    {
        $this->expectException(InvalidApiResponseException::class);

        $this->parser->parseRefundExternalId(['object' => ['orderId' => 123]]);
    }

    #[Test]
    public function it_throws_when_id_is_not_an_integer_in_refund_delete(): void
    {
        $this->expectException(InvalidApiResponseException::class);

        $this->parser->parseRefundExternalId(['object' => ['id' => 'abc']]);
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    /**
     * Valid refund webhook payload matching the Sentry-confirmed structure.
     *
     * @return array<string, mixed>
     */
    private static function validRefundPayload(): array
    {
        return [
            'id' => 128409325,
            'orderId' => 11479559,
            'createdAt' => 'Mon, 16 Mar 2026 10:35:33 +0000',
            'amount' => 14.95,
            'description' => 'Date:16-03-2026>14.95*DOR-SSD1*Not big enough>',
        ];
    }
}
