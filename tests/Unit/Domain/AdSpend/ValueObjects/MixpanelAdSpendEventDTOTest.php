<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AdSpend\ValueObjects;

use App\Infrastructure\Mixpanel\MixpanelAdSpendEventDTO;
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
}
