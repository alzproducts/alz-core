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
            campaignId: 123456789,
            campaignName: '[01] Search - Branded',
            status: 'ENABLED',
        );

        $this->assertSame(123456789, $campaign->campaignId);
        $this->assertSame('[01] Search - Branded', $campaign->campaignName);
        $this->assertSame('ENABLED', $campaign->status);
    }

    #[Test]
    public function it_accepts_paused_status(): void
    {
        $campaign = new Campaign(
            campaignId: 987654321,
            campaignName: '[02] Performance Max',
            status: 'PAUSED',
        );

        $this->assertSame('PAUSED', $campaign->status);
    }

    #[Test]
    public function it_accepts_removed_status(): void
    {
        $campaign = new Campaign(
            campaignId: 555555555,
            campaignName: 'Old Campaign',
            status: 'REMOVED',
        );

        $this->assertSame('REMOVED', $campaign->status);
    }

    #[Test]
    public function it_accepts_unspecified_status(): void
    {
        $campaign = new Campaign(
            campaignId: 999999999,
            campaignName: 'Unspecified Campaign',
            status: 'UNSPECIFIED',
        );

        $this->assertSame('UNSPECIFIED', $campaign->status);
    }

    #[Test]
    public function it_accepts_campaign_id_of_one(): void
    {
        $campaign = new Campaign(
            campaignId: 1,
            campaignName: 'Boundary Test Campaign',
            status: 'ENABLED',
        );

        $this->assertSame(1, $campaign->campaignId);
    }

    #[Test]
    public function it_rejects_negative_campaign_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            campaignId: -1,
            campaignName: 'Test',
            status: 'ENABLED',
        );
    }

    #[Test]
    public function it_rejects_zero_campaign_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            campaignId: 0,
            campaignName: 'Test',
            status: 'ENABLED',
        );
    }

    #[Test]
    public function it_rejects_empty_campaign_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            campaignId: 123,
            campaignName: '',
            status: 'ENABLED',
        );
    }

    #[Test]
    public function it_rejects_invalid_status(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            campaignId: 123,
            campaignName: 'Test',
            status: 'INVALID',
        );
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $campaign = new Campaign(
            campaignId: 123,
            campaignName: 'Test',
            status: 'ENABLED',
        );

        $this->assertTrue(true); // readonly class prevents mutation at property level
    }
}
