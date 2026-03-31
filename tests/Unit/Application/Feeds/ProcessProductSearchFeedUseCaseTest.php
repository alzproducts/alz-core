<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Feeds;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Application\Feeds\ProductSearchFeedProcessingResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedFeedDataException;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
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
 * - Delegation to processor with injected config values
 * - Exception propagation (Application layer doesn't catch)
 * - Logging workflow events
 *
 * Note: Config validation is now handled by DoofinderConfig (Infrastructure).
 * The use case receives pre-validated scalar values via constructor injection.
 */
#[CoversClass(ProcessProductSearchFeedUseCase::class)]
final class ProcessProductSearchFeedUseCaseTest extends TestCase
{
    private const string SOURCE_URL = 'https://example.com/feed';
    private const string STORAGE_PATH = 'feeds/output.xml';

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
            self::SOURCE_URL,
            self::STORAGE_PATH,
            $this->mockLogger,
        );
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
            ->with(self::SOURCE_URL, self::STORAGE_PATH)
            ->andReturn(new ProductSearchFeedProcessingResult(
                itemsProcessed: 100,
                titlesSubstituted: 95,
                durationSeconds: 5.5,
            ));

        $this->useCase->execute();
    }

    #[Test]
    public function it_passes_source_url_to_processor(): void
    {
        $this->mockProcessor
            ->shouldReceive('process')
            ->once()
            ->withArgs(static fn(string $url): bool => $url === self::SOURCE_URL)
            ->andReturn($this->createSuccessResult());

        $this->useCase->execute();
    }

    #[Test]
    public function it_passes_storage_path_to_processor(): void
    {
        $this->mockProcessor
            ->shouldReceive('process')
            ->once()
            ->withArgs(static fn(string $url, string $path): bool => $path === self::STORAGE_PATH)
            ->andReturn($this->createSuccessResult());

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
        $this->expectExceptionMessage('External service unavailable');

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
        $this->expectExceptionMessage('Malformed feed data');

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
        $this->expectExceptionMessage('Storage operation failed');

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
                    && $context['source_url'] === self::SOURCE_URL
                    && $context['output_path'] === self::STORAGE_PATH);

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
