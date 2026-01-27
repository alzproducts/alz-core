<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\RailwayJsonFormatter;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RailwayJsonFormatter::class)]
final class RailwayJsonFormatterTest extends TestCase
{
    private RailwayJsonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new RailwayJsonFormatter();
    }

    #[Test]
    public function it_outputs_valid_json(): void
    {
        $record = $this->createLogRecord(Level::Info, 'Test message');

        $output = $this->formatter->format($record);

        $decoded = \json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertSame('Test message', $decoded['message']);
    }

    #[Test]
    public function it_includes_lowercase_level_field(): void
    {
        $record = $this->createLogRecord(Level::Error, 'Error occurred');

        $output = $this->formatter->format($record);
        $decoded = \json_decode($output, true);

        self::assertArrayHasKey('level', $decoded);
        self::assertSame('error', $decoded['level']);
    }

    #[Test]
    public function it_removes_level_name_field(): void
    {
        $record = $this->createLogRecord(Level::Warning, 'Warning message');

        $output = $this->formatter->format($record);
        $decoded = \json_decode($output, true);

        self::assertArrayNotHasKey('level_name', $decoded);
    }

    #[Test]
    #[DataProvider('levelMappingProvider')]
    public function it_maps_monolog_levels_to_railway_levels(Level $monologLevel, string $expectedRailwayLevel): void
    {
        $record = $this->createLogRecord($monologLevel, 'Test');

        $output = $this->formatter->format($record);
        $decoded = \json_decode($output, true);

        self::assertSame($expectedRailwayLevel, $decoded['level']);
    }

    /**
     * @return array<string, array{Level, string}>
     */
    public static function levelMappingProvider(): array
    {
        return [
            'debug maps to debug' => [Level::Debug, 'debug'],
            'info maps to info' => [Level::Info, 'info'],
            'notice maps to info' => [Level::Notice, 'info'],
            'warning maps to warn' => [Level::Warning, 'warn'],
            'error maps to error' => [Level::Error, 'error'],
            'critical maps to error' => [Level::Critical, 'error'],
            'alert maps to error' => [Level::Alert, 'error'],
            'emergency maps to error' => [Level::Emergency, 'error'],
        ];
    }

    #[Test]
    public function it_preserves_context_data(): void
    {
        $record = $this->createLogRecord(
            Level::Info,
            'Order processed',
            ['order_id' => 12345, 'customer' => 'test@example.com'],
        );

        $output = $this->formatter->format($record);
        $decoded = \json_decode($output, true);

        self::assertSame(12345, $decoded['context']['order_id']);
        self::assertSame('test@example.com', $decoded['context']['customer']);
    }

    #[Test]
    public function it_ends_output_with_newline(): void
    {
        $record = $this->createLogRecord(Level::Info, 'Test');

        $output = $this->formatter->format($record);

        self::assertStringEndsWith("\n", $output);
    }

    #[Test]
    public function it_includes_channel_in_output(): void
    {
        $record = $this->createLogRecord(Level::Info, 'Test', [], 'production');

        $output = $this->formatter->format($record);
        $decoded = \json_decode($output, true);

        self::assertSame('production', $decoded['channel']);
    }

    #[Test]
    public function it_includes_datetime_in_output(): void
    {
        $record = $this->createLogRecord(Level::Info, 'Test');

        $output = $this->formatter->format($record);
        $decoded = \json_decode($output, true);

        self::assertArrayHasKey('datetime', $decoded);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function createLogRecord(
        Level $level,
        string $message,
        array $context = [],
        string $channel = 'test',
    ): LogRecord {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: $channel,
            level: $level,
            message: $message,
            context: $context,
        );
    }
}
