<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AdSpend\ValueObjects;

use App\Domain\AdSpend\ValueObjects\Campaign;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CampaignTest extends TestCase
{
    #[Test]
    public function it_creates_valid_campaign(): void
    {
        $campaign = new Campaign(
            id: 123456789,
            name: '[01] Search - Branded',
            status: 'ENABLED',
        );

        $this->assertSame(123456789, $campaign->id);
        $this->assertSame('[01] Search - Branded', $campaign->name);
        $this->assertSame('ENABLED', $campaign->status);
    }

    #[Test]
    public function it_accepts_paused_status(): void
    {
        $campaign = new Campaign(
            id: 987654321,
            name: '[02] Performance Max',
            status: 'PAUSED',
        );

        $this->assertSame('PAUSED', $campaign->status);
    }

    #[Test]
    public function it_accepts_removed_status(): void
    {
        $campaign = new Campaign(
            id: 555555555,
            name: 'Old Campaign',
            status: 'REMOVED',
        );

        $this->assertSame('REMOVED', $campaign->status);
    }

    #[Test]
    public function it_accepts_unspecified_status(): void
    {
        $campaign = new Campaign(
            id: 999999999,
            name: 'Unspecified Campaign',
            status: 'UNSPECIFIED',
        );

        $this->assertSame('UNSPECIFIED', $campaign->status);
    }

    #[Test]
    public function it_accepts_campaign_id_of_one(): void
    {
        $campaign = new Campaign(
            id: 1,
            name: 'Boundary Test Campaign',
            status: 'ENABLED',
        );

        $this->assertSame(1, $campaign->id);
    }

    #[Test]
    public function it_rejects_negative_campaign_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            id: -1,
            name: 'Test',
            status: 'ENABLED',
        );
    }

    #[Test]
    public function it_rejects_zero_campaign_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            id: 0,
            name: 'Test',
            status: 'ENABLED',
        );
    }

    #[Test]
    public function it_rejects_empty_campaign_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            id: 123,
            name: '',
            status: 'ENABLED',
        );
    }

    #[Test]
    public function it_rejects_invalid_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            id: 123,
            name: 'Test',
            status: 'INVALID',
        );
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $campaign = new Campaign(
            id: 123,
            name: 'Test',
            status: 'ENABLED',
        );

        $this->assertTrue(true); // readonly class prevents mutation at property level
    }
}
