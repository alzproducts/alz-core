<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Feeds;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Application\Feeds\ProductSearchFeedProcessingResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedFeedDataException;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * ProcessProductSearchFeedUseCase Unit Tests.
 *
 * Tests the use case orchestration:
 * - Configuration validation
 * - Delegation to processor
 * - Exception propagation (Application layer doesn't catch)
 * - Logging workflow events
 */
#[CoversClass(ProcessProductSearchFeedUseCase::class)]
final class ProcessProductSearchFeedUseCaseTest extends TestCase
{
    private ProductSearchFeedProcessorInterface&MockInterface $mockProcessor;
    private LoggerInterface&MockInterface $mockLogger;
    private ProcessProductSearchFeedUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockProcessor = Mockery::mock(ProductSearchFeedProcessorInterface::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldReceive('info')->byDefault();

        $this->useCase = new ProcessProductSearchFeedUseCase(
            $this->mockProcessor,
            $this->mockLogger,
        );

        // Default valid config
        Config::set('feeds.doofinder', [
            'source_url' => 'https://example.com/feed',
            'storage_path' => 'feeds/output.xml',
            'storage_disk' => 's3',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_executes_feed_processing_successfully(): void
    {
        $this->mockProcessor
            ->shouldReceive('process')
            ->once()
            ->with('https://example.com/feed', 'feeds/output.xml')
            ->andReturn(new ProductSearchFeedProcessingResult(
                itemsProcessed: 100,
                titlesSubstituted: 95,
                durationSeconds: 5.5,
            ));

        $this->useCase->execute();
    }

    #[Test]
    public function it_passes_source_url_from_config(): void
    {
        Config::set('feeds.doofinder.source_url', 'https://custom.example.com/products.xml');

        $this->mockProcessor
            ->shouldReceive('process')
            ->once()
            ->withArgs(static fn(string $url): bool => $url === 'https://custom.example.com/products.xml')
            ->andReturn($this->createSuccessResult());

        $this->useCase->execute();
    }

    #[Test]
    public function it_passes_storage_path_from_config(): void
    {
        Config::set('feeds.doofinder.storage_path', 'custom/path/feed.xml');

        $this->mockProcessor
            ->shouldReceive('process')
            ->once()
            ->withArgs(static fn(string $url, string $path): bool => $path === 'custom/path/feed.xml')
            ->andReturn($this->createSuccessResult());

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_config_is_missing(): void
    {
        Config::set('feeds.doofinder', null);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Product search feed configuration is missing');

        $this->useCase->execute();
    }

    #[Test]
    public function it_throws_when_config_is_not_array(): void
    {
        Config::set('feeds.doofinder', 'invalid');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Product search feed configuration is missing');

        $this->useCase->execute();
    }

    #[Test]
    public function it_throws_when_source_url_is_missing(): void
    {
        Config::set('feeds.doofinder', [
            'storage_path' => 'feeds/output.xml',
            'storage_disk' => 's3',
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing required key: source_url');

        $this->useCase->execute();
    }

    #[Test]
    public function it_throws_when_storage_path_is_missing(): void
    {
        Config::set('feeds.doofinder', [
            'source_url' => 'https://example.com/feed',
            'storage_disk' => 's3',
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing required key: storage_path');

        $this->useCase->execute();
    }

    #[Test]
    public function it_throws_when_storage_disk_is_missing(): void
    {
        Config::set('feeds.doofinder', [
            'source_url' => 'https://example.com/feed',
            'storage_path' => 'feeds/output.xml',
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing required key: storage_disk');

        $this->useCase->execute();
    }

    #[Test]
    public function it_throws_when_source_url_is_empty(): void
    {
        Config::set('feeds.doofinder.source_url', '');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing required key: source_url');

        $this->useCase->execute();
    }

    #[Test]
    public function it_throws_when_storage_path_is_empty(): void
    {
        Config::set('feeds.doofinder.storage_path', '');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing required key: storage_path');

        $this->useCase->execute();
    }

    #[Test]
    public function it_throws_when_config_value_is_not_string(): void
    {
        Config::set('feeds.doofinder.source_url', 123);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('missing required key: source_url');

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Propagation Tests (Application layer doesn't catch)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_propagates_external_service_unavailable_exception(): void
    {
        $exception = new ExternalServiceUnavailableException('Doofinder Feed', 300);

        $this->mockProcessor
            ->shouldReceive('process')
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Doofinder Feed' is unavailable");

        $this->useCase->execute();
    }

    #[Test]
    public function it_propagates_malformed_feed_data_exception(): void
    {
        $exception = new MalformedFeedDataException('Doofinder Feed', 'Invalid XML');

        $this->mockProcessor
            ->shouldReceive('process')
            ->andThrow($exception);

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('Invalid XML');

        $this->useCase->execute();
    }

    #[Test]
    public function it_propagates_storage_operation_failed_exception(): void
    {
        $exception = new StorageOperationFailedException('upload', 'feeds/output.xml', 'S3 error');

        $this->mockProcessor
            ->shouldReceive('process')
            ->andThrow($exception);

        $this->expectException(StorageOperationFailedException::class);
        $this->expectExceptionMessage('S3 error');

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_start_of_processing(): void
    {
        $this->mockProcessor
            ->shouldReceive('process')
            ->andReturn($this->createSuccessResult());

        $this->mockLogger
            ->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Starting product search feed processing')
                    && $context['source_url'] === 'https://example.com/feed'
                    && $context['output_path'] === 'feeds/output.xml');

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_completion_with_stats(): void
    {
        $this->mockProcessor
            ->shouldReceive('process')
            ->andReturn(new ProductSearchFeedProcessingResult(
                itemsProcessed: 500,
                titlesSubstituted: 480,
                durationSeconds: 12.5,
            ));

        $this->mockLogger
            ->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Product search feed processing completed')
                    && $context['items_processed'] === 500
                    && $context['titles_substituted'] === 480
                    && $context['duration_seconds'] === 12.5);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createSuccessResult(): ProductSearchFeedProcessingResult
    {
        return new ProductSearchFeedProcessingResult(
            itemsProcessed: 100,
            titlesSubstituted: 95,
            durationSeconds: 5.0,
        );
    }
}
