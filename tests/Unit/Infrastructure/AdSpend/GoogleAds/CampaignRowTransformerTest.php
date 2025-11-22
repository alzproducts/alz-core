<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\AdSpend\GoogleAds;

use App\Domain\AdSpend\Exceptions\InvalidGoogleAdsResponseException;
use App\Domain\AdSpend\ValueObjects\Campaign;
use App\Infrastructure\AdSpend\GoogleAds\CampaignRowTransformer;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CampaignRowTransformer::class)]
final class CampaignRowTransformerTest extends TestCase
{
    #[Test]
    public function it_transforms_row_with_enabled_status(): void
    {
        $row = $this->createMockRow(
            campaignId: 123456789,
            campaignName: '[01] Search - Branded',
            status: 1,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(123456789, $campaign->campaignId);
        $this->assertSame('[01] Search - Branded', $campaign->campaignName);
        $this->assertSame('ENABLED', $campaign->status);
    }

    #[Test]
    public function it_transforms_row_with_paused_status(): void
    {
        $row = $this->createMockRow(
            campaignId: 987654321,
            campaignName: '[02] Performance Max',
            status: 2,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(987654321, $campaign->campaignId);
        $this->assertSame('[02] Performance Max', $campaign->campaignName);
        $this->assertSame('PAUSED', $campaign->status);
    }

    #[Test]
    public function it_transforms_row_with_removed_status(): void
    {
        $row = $this->createMockRow(
            campaignId: 555555555,
            campaignName: 'Old Campaign',
            status: 3,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(555555555, $campaign->campaignId);
        $this->assertSame('Old Campaign', $campaign->campaignName);
        $this->assertSame('REMOVED', $campaign->status);
    }

    #[Test]
    public function it_transforms_row_with_unspecified_status(): void
    {
        $row = $this->createMockRow(
            campaignId: 111111111,
            campaignName: 'Unspecified Campaign',
            status: 0,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(111111111, $campaign->campaignId);
        $this->assertSame('Unspecified Campaign', $campaign->campaignName);
        $this->assertSame('UNSPECIFIED', $campaign->status);
    }

    #[Test]
    #[DataProvider('allValidStatusEnums')]
    public function it_maps_all_valid_status_enums(int $enumValue, string $expectedStatus): void
    {
        $row = $this->createMockRow(
            campaignId: 1000,
            campaignName: 'Test Campaign',
            status: $enumValue,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($expectedStatus, $campaign->status);
    }

    public static function allValidStatusEnums(): array
    {
        return [
            'UNSPECIFIED (0)' => [0, 'UNSPECIFIED'],
            'ENABLED (1)' => [1, 'ENABLED'],
            'PAUSED (2)' => [2, 'PAUSED'],
            'REMOVED (3)' => [3, 'REMOVED'],
        ];
    }

    #[Test]
    public function it_throws_when_campaign_is_null(): void
    {
        $row = $this->getMockBuilder(GoogleAdsRow::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCampaign'])
            ->getMock();

        $row->method('getCampaign')->willReturn(null);

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('campaign');

        CampaignRowTransformer::toCampaign($row);
    }

    #[Test]
    public function it_throws_on_invalid_status_enum_value(): void
    {
        $row = $this->createMockRow(
            campaignId: 123,
            campaignName: 'Test',
            status: 99,
        );

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Unknown campaign status enum value: 99');

        CampaignRowTransformer::toCampaign($row);
    }

    #[Test]
    public function it_throws_on_negative_status_enum_value(): void
    {
        $row = $this->createMockRow(
            campaignId: 123,
            campaignName: 'Test',
            status: -1,
        );

        $this->expectException(InvalidGoogleAdsResponseException::class);
        $this->expectExceptionMessage('Unknown campaign status enum value: -1');

        CampaignRowTransformer::toCampaign($row);
    }

    #[Test]
    public function it_casts_campaign_id_to_int(): void
    {
        $row = $this->createMockRow(
            campaignId: '123456789',
            campaignName: 'Test Campaign',
            status: 1,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame(123456789, $campaign->campaignId);
        $this->assertIsInt($campaign->campaignId);
    }

    #[Test]
    public function it_preserves_campaign_name_unchanged(): void
    {
        $campaignName = '[01] Search - Branded | Special (Characters)';
        $row = $this->createMockRow(
            campaignId: 123,
            campaignName: $campaignName,
            status: 1,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($campaignName, $campaign->campaignName);
    }

    #[Test]
    public function it_handles_large_campaign_ids(): void
    {
        $largeId = PHP_INT_MAX;
        $row = $this->createMockRow(
            campaignId: $largeId,
            campaignName: 'Large ID Campaign',
            status: 1,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($largeId, $campaign->campaignId);
    }

    #[Test]
    public function it_handles_special_characters_in_campaign_name(): void
    {
        $specialName = 'Campaign With "Quotes" & <Special> [Brackets]';
        $row = $this->createMockRow(
            campaignId: 123,
            campaignName: $specialName,
            status: 1,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($specialName, $campaign->campaignName);
    }

    #[Test]
    public function it_handles_whitespace_in_campaign_name(): void
    {
        $nameWithWhitespace = "Campaign  With   Multiple   Spaces\nAnd\tTabs";
        $row = $this->createMockRow(
            campaignId: 123,
            campaignName: $nameWithWhitespace,
            status: 1,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertSame($nameWithWhitespace, $campaign->campaignName);
    }

    #[Test]
    public function it_returns_campaign_value_object(): void
    {
        $row = $this->createMockRow(
            campaignId: 123,
            campaignName: 'Test',
            status: 1,
        );

        $campaign = CampaignRowTransformer::toCampaign($row);

        $this->assertInstanceOf(Campaign::class, $campaign);
    }

    /**
     * Helper method to create a mock GoogleAdsRow with campaign data.
     * Follows SDK pattern: GoogleAdsRow::getCampaign() returns Campaign object with getter methods.
     *
     * @param int|string $campaignId
     */
    private function createMockRow(
        int|string $campaignId,
        string $campaignName,
        int $status,
    ): GoogleAdsRow {
        // Create a simple object to represent the Campaign with the required methods
        $campaign = new class ($campaignId, $campaignName, $status) {
            public function __construct(
                private readonly int|string $id,
                private readonly string $name,
                private readonly int $statusCode,
            ) {}

            public function getId(): int|string
            {
                return $this->id;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getStatus(): int
            {
                return $this->statusCode;
            }
        };

        // Create mock GoogleAdsRow that returns the campaign object
        $row = $this->getMockBuilder(GoogleAdsRow::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCampaign'])
            ->getMock();

        $row->method('getCampaign')->willReturn($campaign);

        return $row;
    }
}
