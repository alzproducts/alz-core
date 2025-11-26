<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\AdSpend\ValueObjects;

use App\Domain\AdSpend\ValueObjects\CampaignMetrics;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CampaignMetrics::class)]
final class CampaignMetricsTest extends TestCase
{
    #[Test]
    public function it_creates_a_valid_instance_on_happy_path(): void
    {
        // Arrange
        $data = [
            'campaignId' => 123,
            'campaignName' => 'Test Campaign',
            'date' => '2023-10-26',
            'costInPounds' => 50.75,
            'clicks' => 100,
            'impressions' => 5000,
            'conversions' => 5.5,
        ];

        // Act
        $metrics = new CampaignMetrics(...$data);

        // Assert
        self::assertSame($data['campaignId'], $metrics->campaignId);
        self::assertSame($data['campaignName'], $metrics->campaignName);
        self::assertSame($data['date'], $metrics->date);
        self::assertSame($data['costInPounds'], $metrics->costInPounds);
        self::assertSame($data['clicks'], $metrics->clicks);
        self::assertSame($data['impressions'], $metrics->impressions);
        self::assertSame($data['conversions'], $metrics->conversions);
    }

    #[Test]
    public function it_allows_zero_values_for_metrics(): void
    {
        // Arrange & Act
        $metrics = new CampaignMetrics(
            campaignId: 1,
            campaignName: 'Zero Value Campaign',
            date: '2023-10-27',
            costInPounds: 0.0,
            clicks: 0,
            impressions: 0,
            conversions: 0.0,
        );

        // Assert
        self::assertSame(0.0, $metrics->costInPounds);
        self::assertSame(0, $metrics->clicks);
        self::assertSame(0, $metrics->impressions);
        self::assertSame(0.0, $metrics->conversions);
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

        // Arrange: merge invalid data with valid defaults
        $data = \array_merge([
            'campaignId' => 123,
            'campaignName' => 'Valid Campaign',
            'date' => '2023-10-26',
            'costInPounds' => 10.0,
            'clicks' => 100,
            'impressions' => 1000,
            'conversions' => 5.0,
        ], $invalidData);

        // Act
        new CampaignMetrics(...$data);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function invalidConstructorArgumentsProvider(): array
    {
        return [
            'campaignId is zero' => [['campaignId' => 0], 'Campaign ID must be positive'],
            'campaignId is negative' => [['campaignId' => -1], 'Campaign ID must be positive'],
            'campaignName is empty' => [['campaignName' => ''], 'Campaign name cannot be empty'],
            'date is invalid format' => [['date' => '26-10-2023'], 'Date must be YYYY-MM-DD format'],
            'date is not a date string' => [['date' => 'not-a-date'], 'Date must be YYYY-MM-DD format'],
            'costInPounds is negative' => [['costInPounds' => -0.01], 'Cost cannot be negative'],
            'clicks is negative' => [['clicks' => -1], 'Clicks cannot be negative'],
            'impressions is negative' => [['impressions' => -1], 'Impressions cannot be negative'],
            'conversions is negative' => [['conversions' => -1.0], 'Conversions cannot be negative'],
        ];
    }

}
