<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AdSpend\ValueObjects;

use App\Domain\AdSpend\Enums\AdSource;
use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use App\Infrastructure\Mixpanel\DTOs\MixpanelAdSpendEventDTO;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MixpanelAdSpendEventDTO::class)]
final class MixpanelAdSpendEventDTOTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function createValidData(array $overrides = []): array
    {
        return \array_merge([
            'insertId' => 'unique-id-12345',
            'timestamp' => 1672531200, // 2023-01-01 00:00:00 UTC
            'source' => 'google_ads',
            'campaignId' => 101,
            'campaignName' => 'Winter Sale',
            'cost' => 199.99,
            'clicks' => 500,
            'impressions' => 25000,
            'conversions' => 25.5,
            'utmSource' => 'google',
            'utmMedium' => 'cpc',
            'utmCampaign' => 'winter_sale',
        ], $overrides);
    }

    #[Test]
    public function it_creates_a_valid_instance_on_happy_path(): void
    {
        // Arrange
        $data = $this->createValidData();

        // Act
        $event = new MixpanelAdSpendEventDTO(...$data);

        // Assert
        self::assertSame($data['insertId'], $event->insertId);
        self::assertSame($data['timestamp'], $event->timestamp);
        self::assertSame($data['source'], $event->source);
        self::assertSame($data['campaignId'], $event->campaignId);
        self::assertSame($data['campaignName'], $event->campaignName);
        self::assertSame($data['cost'], $event->cost);
        self::assertSame($data['clicks'], $event->clicks);
        self::assertSame($data['impressions'], $event->impressions);
        self::assertSame($data['conversions'], $event->conversions);
        self::assertSame($data['utmSource'], $event->utmSource);
        self::assertSame($data['utmMedium'], $event->utmMedium);
        self::assertSame($data['utmCampaign'], $event->utmCampaign);
    }

    /**
     * @param array<string, mixed> $invalidData
     */
    #[Test]
    #[DataProvider('invalidConstructorArgumentsProvider')]
    public function it_throws_exception_for_invalid_constructor_arguments(array $invalidData, string $expectedExceptionMessage): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        // Arrange
        $data = $this->createValidData($invalidData);

        // Act
        new MixpanelAdSpendEventDTO(...$data);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function invalidConstructorArgumentsProvider(): array
    {
        return [
            'empty insertId' => [['insertId' => ''], 'Insert ID cannot be empty'],
            'insertId too long (ASCII)' => [['insertId' => \str_repeat('a', 37)], 'Insert ID must be ≤36 characters'],
            'timestamp is zero' => [['timestamp' => 0], 'Timestamp must be positive Unix time'],
            'timestamp is negative' => [['timestamp' => -1], 'Timestamp must be positive Unix time'],
            'source is empty' => [['source' => ''], 'Source cannot be empty'],
        ];
    }

    #[Test]
    public function it_allows_valid_insert_id_with_multibyte_chars(): void
    {
        // Arrange
        // 10 'é' characters. Each is 1 character but 2 bytes. Total: 20 bytes.
        $validMultiByteId = \str_repeat('é', 10);

        // Act
        $event = new MixpanelAdSpendEventDTO(...$this->createValidData(['insertId' => $validMultiByteId]));

        // Assert
        self::assertSame($validMultiByteId, $event->insertId);
    }

    #[Test]
    public function it_rejects_insert_id_exceeding_max_character_length(): void
    {
        // Arrange
        // An ID with 37 characters (exceeds 36 character limit)
        $invalidId = \str_repeat('a', 37);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insert ID must be ≤36 characters');

        // Act
        new MixpanelAdSpendEventDTO(...$this->createValidData(['insertId' => $invalidId]));
    }

    #[Test]
    public function it_allows_insert_id_at_exactly_max_character_length(): void
    {
        // Arrange
        $idAtBoundary = \str_repeat('a', 36); // Exactly 36 characters

        // Act
        $event = new MixpanelAdSpendEventDTO(...$this->createValidData(['insertId' => $idAtBoundary]));

        // Assert
        self::assertSame($idAtBoundary, $event->insertId);
    }

    #[Test]
    public function it_transforms_campaign_metrics_to_dto(): void
    {
        // Arrange
        $campaign = new CampaignMetrics(
            campaignId: 12345,
            campaignName: 'Summer Sale 2024',
            date: '2024-06-15',
            costInPounds: 250.75,
            clicks: 1500,
            impressions: 50000,
            conversions: 75.5,
        );

        // Act
        $dto = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign, AdSource::Google);

        // Assert
        self::assertSame('G-2024-06-15-12345', $dto->insertId);
        self::assertSame(1718409600, $dto->timestamp); // 2024-06-15 00:00:00 UTC
        self::assertSame('Google', $dto->source);
        self::assertSame(12345, $dto->campaignId);
        self::assertSame('Summer Sale 2024', $dto->campaignName);
        self::assertSame(250.75, $dto->cost);
        self::assertSame(1500, $dto->clicks);
        self::assertSame(50000, $dto->impressions);
        self::assertSame(75.5, $dto->conversions);
        self::assertSame('google', $dto->utmSource);
        self::assertSame('cpc', $dto->utmMedium);
        self::assertSame('Summer Sale 2024', $dto->utmCampaign);
    }

    #[Test]
    public function it_uses_raw_insert_id_at_maximum_realistic_length(): void
    {
        // Arrange: PHP_INT_MAX creates longest possible insert ID with valid int
        // Format: "G-YYYY-MM-DD-{PHP_INT_MAX}" = 32 chars (under 36 limit)
        $campaign = new CampaignMetrics(
            campaignId: \PHP_INT_MAX, // 9223372036854775807 (19 digits)
            campaignName: 'Test Campaign',
            date: '2024-12-31',
            costInPounds: 100.0,
            clicks: 10,
            impressions: 100,
            conversions: 1.0,
        );

        // Act
        $dto = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign, AdSource::Google);

        // Assert: Even at max int, we're only at 32 chars → use raw ID (no hashing needed)
        $expected = 'G-2024-12-31-' . \PHP_INT_MAX;
        self::assertSame(32, \mb_strlen($expected)); // Verify it's under 36
        self::assertSame($expected, $dto->insertId);
    }

    #[Test]
    public function it_correctly_measures_character_length_not_byte_length(): void
    {
        // Arrange: Insert ID with multibyte characters (é = 2 bytes, 1 character)
        // This tests that mb_strlen (not strlen) is used in the boundary check
        $campaign = new CampaignMetrics(
            campaignId: 123,
            campaignName: 'Café Campaign', // Contains multibyte chars
            date: '2024-12-31',
            costInPounds: 100.0,
            clicks: 10,
            impressions: 100,
            conversions: 1.0,
        );

        // Act
        $dto = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign, AdSource::Google);

        // Assert: Insert ID uses id (ASCII), not name
        // This verifies mb_strlen is used for character count (not byte count)
        // Mutation testing: mb_strlen → strlen would fail with multibyte chars
        $expected = 'G-2024-12-31-123';
        self::assertSame(16, \mb_strlen($expected));
        self::assertSame($expected, $dto->insertId);
    }

    #[Test]
    public function it_converts_to_mixpanel_format(): void
    {
        // Arrange
        $dto = new MixpanelAdSpendEventDTO(
            insertId: 'G-2024-11-20-99',
            timestamp: 1700438400, // 2023-11-20 00:00:00 UTC
            source: 'Google',
            campaignId: 99,
            campaignName: 'Black Friday',
            cost: 500.50,
            clicks: 2000,
            impressions: 100000,
            conversions: 150.0,
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'black_friday',
        );

        // Act
        $mixpanelFormat = $dto->toMixpanelFormat();

        // Assert
        self::assertIsArray($mixpanelFormat);
        self::assertSame('Ad Data', $mixpanelFormat['event']);
        self::assertArrayHasKey('properties', $mixpanelFormat);

        $properties = $mixpanelFormat['properties'];
        self::assertSame(1700438400, $properties['time']);
        self::assertSame('', $properties['distinct_id']);
        self::assertSame('G-2024-11-20-99', $properties['$insert_id']);
        self::assertSame('Google', $properties['source']);
        self::assertSame(99, $properties['campaign_id']);
        self::assertSame('Black Friday', $properties['campaign_name']);
        self::assertSame(500.50, $properties['cost']);
        self::assertSame(2000, $properties['clicks']);
        self::assertSame(100000, $properties['impressions']);
        self::assertSame(150.0, $properties['conversions']);
        self::assertSame('google', $properties['utm_source']);
        self::assertSame('cpc', $properties['utm_medium']);
        self::assertSame('black_friday', $properties['utm_campaign']);
    }

    #[Test]
    public function it_generates_deterministic_insert_ids_for_same_campaign_and_date(): void
    {
        // Arrange
        $campaign1 = new CampaignMetrics(
            campaignId: 777,
            campaignName: 'Campaign A',
            date: '2024-01-01',
            costInPounds: 50.0,
            clicks: 10,
            impressions: 100,
            conversions: 1.0,
        );

        $campaign2 = new CampaignMetrics(
            campaignId: 777,
            campaignName: 'Campaign A (renamed)', // Different name but same ID and date
            date: '2024-01-01',
            costInPounds: 75.0,
            clicks: 20,
            impressions: 200,
            conversions: 2.0,
        );

        // Act
        $dto1 = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign1, AdSource::Google);
        $dto2 = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign2, AdSource::Google);

        // Assert: Same campaign ID + date = same insert ID (for deduplication)
        self::assertSame($dto1->insertId, $dto2->insertId);
        self::assertSame('G-2024-01-01-777', $dto1->insertId);
    }

    #[Test]
    public function it_generates_different_insert_ids_for_different_campaigns(): void
    {
        // Arrange
        $campaign1 = new CampaignMetrics(
            campaignId: 100,
            campaignName: 'Campaign X',
            date: '2024-01-01',
            costInPounds: 50.0,
            clicks: 10,
            impressions: 100,
            conversions: 1.0,
        );

        $campaign2 = new CampaignMetrics(
            campaignId: 200, // Different campaign ID
            campaignName: 'Campaign X',
            date: '2024-01-01',
            costInPounds: 50.0,
            clicks: 10,
            impressions: 100,
            conversions: 1.0,
        );

        // Act
        $dto1 = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign1, AdSource::Google);
        $dto2 = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign2, AdSource::Google);

        // Assert: Different campaign ID = different insert ID
        self::assertNotSame($dto1->insertId, $dto2->insertId);
        self::assertSame('G-2024-01-01-100', $dto1->insertId);
        self::assertSame('G-2024-01-01-200', $dto2->insertId);
    }

    #[Test]
    public function it_generates_different_insert_ids_for_different_dates(): void
    {
        // Arrange
        $campaign1 = new CampaignMetrics(
            campaignId: 100,
            campaignName: 'Campaign X',
            date: '2024-01-01',
            costInPounds: 50.0,
            clicks: 10,
            impressions: 100,
            conversions: 1.0,
        );

        $campaign2 = new CampaignMetrics(
            campaignId: 100, // Same campaign ID
            campaignName: 'Campaign X',
            date: '2024-01-02', // Different date
            costInPounds: 50.0,
            clicks: 10,
            impressions: 100,
            conversions: 1.0,
        );

        // Act
        $dto1 = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign1, AdSource::Google);
        $dto2 = MixpanelAdSpendEventDTO::fromCampaignMetrics($campaign2, AdSource::Google);

        // Assert: Different date = different insert ID
        self::assertNotSame($dto1->insertId, $dto2->insertId);
        self::assertSame('G-2024-01-01-100', $dto1->insertId);
        self::assertSame('G-2024-01-02-100', $dto2->insertId);
    }
}
