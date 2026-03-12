<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Shopwired\Webhooks\DTOs;

use App\Presentation\Http\Shopwired\Webhooks\DTOs\WebhookEnvelopeDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(WebhookEnvelopeDTO::class)]
final class WebhookEnvelopeDTOTest extends TestCase
{
    #[Test]
    public function it_parses_rfc_2822_timestamp(): void
    {
        $dto = WebhookEnvelopeDTO::from([
            'timestamp' => 'Thu, 12 Mar 2026 18:33:01 +0000',
            'event' => [
                'id' => 1,
                'topic' => 'product.updated',
                'subjectId' => 42,
                'data' => ['name' => 'Test Product'],
            ],
        ]);

        $this->assertSame('2026-03-12 18:33:01', $dto->timestamp->format('Y-m-d H:i:s'));
        $this->assertSame('+00:00', $dto->timestamp->getTimezone()->getName());
    }

    #[Test]
    public function it_parses_iso_8601_timestamp(): void
    {
        $dto = WebhookEnvelopeDTO::from([
            'timestamp' => '2026-03-12T18:33:01+00:00',
            'event' => [
                'id' => 1,
                'topic' => 'order.created',
                'subjectId' => 99,
                'data' => ['total' => '49.99'],
            ],
        ]);

        $this->assertSame('2026-03-12 18:33:01', $dto->timestamp->format('Y-m-d H:i:s'));
        $this->assertSame('+00:00', $dto->timestamp->getTimezone()->getName());
    }
}
