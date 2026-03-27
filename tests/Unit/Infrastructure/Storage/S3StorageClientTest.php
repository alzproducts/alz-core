<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Storage;

use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use App\Infrastructure\Storage\S3StorageClient;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * S3StorageClient Unit Tests.
 *
 * Tests the storage client's:
 * - File upload operations with logging
 * - Temporary URL generation
 * - File existence checks
 * - Exception translation to Domain exceptions
 */
#[CoversClass(S3StorageClient::class)]
final class S3StorageClientTest extends TestCase
{
    private FilesystemFactory&MockInterface $mockFilesystemFactory;
    private FilesystemAdapter&MockInterface $mockFilesystem;
    private LoggerInterface&MockInterface $mockLogger;
    private S3StorageClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockFilesystemFactory = Mockery::mock(FilesystemFactory::class);
        $this->mockFilesystem = Mockery::mock(FilesystemAdapter::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldReceive('debug', 'error')->byDefault();

        $this->mockFilesystemFactory
            ->shouldReceive('disk')
            ->with('s3')
            ->andReturn($this->mockFilesystem)
            ->byDefault();

        $this->client = new S3StorageClient(
            disk: 's3',
            filesystemFactory: $this->mockFilesystemFactory,
            logger: $this->mockLogger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | put() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uploads_file_successfully(): void
    {
        $this->mockFilesystem
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', 'file content');

        $this->client->put('feeds/output.xml', 'file content');
    }

    #[Test]
    public function it_logs_successful_upload(): void
    {
        $this->mockFilesystem->shouldReceive('put')->once();

        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'File uploaded')
                    && $context['disk'] === 's3'
                    && $context['path'] === 'feeds/output.xml'
                    && $context['size_bytes'] === 12);

        $this->client->put('feeds/output.xml', 'file content');
    }

    #[Test]
    public function it_throws_storage_exception_on_unable_to_write(): void
    {
        $writeException = UnableToWriteFile::atLocation('feeds/output.xml', 'Permission denied');

        $this->mockFilesystem
            ->shouldReceive('put')
            ->andThrow($writeException);

        $this->expectException(StorageOperationFailedException::class);
        $this->expectExceptionMessage('Storage operation failed');

        $this->client->put('feeds/output.xml', 'content');
    }

    #[Test]
    public function it_includes_operation_in_storage_exception(): void
    {
        $this->mockFilesystem
            ->shouldReceive('put')
            ->andThrow(new RuntimeException('Network error'));

        try {
            $this->client->put('feeds/output.xml', 'content');
            $this->fail('Expected StorageOperationFailedException');
        } catch (StorageOperationFailedException $e) {
            $this->assertSame('upload', $e->operation);
            $this->assertSame('feeds/output.xml', $e->path);
        }
    }

    #[Test]
    public function it_preserves_original_exception_on_upload_failure(): void
    {
        $originalException = new RuntimeException('S3 bucket not found');

        $this->mockFilesystem
            ->shouldReceive('put')
            ->andThrow($originalException);

        try {
            $this->client->put('feeds/output.xml', 'content');
            $this->fail('Expected StorageOperationFailedException');
        } catch (StorageOperationFailedException $e) {
            $this->assertSame($originalException, $e->getPrevious());
        }
    }

    #[Test]
    public function it_logs_error_on_upload_failure(): void
    {
        $this->mockFilesystem
            ->shouldReceive('put')
            ->andThrow(new RuntimeException('Network timeout'));

        $this->mockLogger
            ->shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Storage operation failed')
                    && $context['operation'] === 'upload'
                    && $context['path'] === 'feeds/output.xml'
                    && \str_contains($context['error'], 'Network timeout'));

        try {
            $this->client->put('feeds/output.xml', 'content');
        } catch (StorageOperationFailedException) {
            // Expected
        }
    }

    /*
    |--------------------------------------------------------------------------
    | temporaryUrl() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_generates_temporary_url(): void
    {
        $expiration = new DateTimeImmutable('+1 hour');
        $expectedUrl = 'https://s3.example.com/feeds/output.xml?signed=abc123';

        $this->mockFilesystem
            ->shouldReceive('temporaryUrl')
            ->once()
            ->with('feeds/output.xml', $expiration)
            ->andReturn($expectedUrl);

        $result = $this->client->temporaryUrl('feeds/output.xml', $expiration);

        $this->assertSame($expectedUrl, $result);
    }

    #[Test]
    public function it_logs_temporary_url_generation(): void
    {
        $expiration = new DateTimeImmutable('2024-12-05T15:00:00+00:00');

        $this->mockFilesystem
            ->shouldReceive('temporaryUrl')
            ->andReturn('https://example.com/signed-url');

        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Generated temporary URL')
                    && $context['disk'] === 's3'
                    && $context['path'] === 'feeds/output.xml'
                    && $context['expires'] === '2024-12-05T15:00:00+00:00');

        $this->client->temporaryUrl('feeds/output.xml', $expiration);
    }

    #[Test]
    public function it_throws_storage_exception_on_temporary_url_failure(): void
    {
        $expiration = new DateTimeImmutable('+1 hour');

        $this->mockFilesystem
            ->shouldReceive('temporaryUrl')
            ->andThrow(new RuntimeException('File not found'));

        $this->expectException(StorageOperationFailedException::class);
        $this->expectExceptionMessage('Storage operation failed');

        $this->client->temporaryUrl('feeds/output.xml', $expiration);
    }

    #[Test]
    public function it_includes_correct_operation_in_temporary_url_exception(): void
    {
        $expiration = new DateTimeImmutable('+1 hour');

        $this->mockFilesystem
            ->shouldReceive('temporaryUrl')
            ->andThrow(new RuntimeException('Error'));

        try {
            $this->client->temporaryUrl('feeds/output.xml', $expiration);
            $this->fail('Expected StorageOperationFailedException');
        } catch (StorageOperationFailedException $e) {
            $this->assertSame('temporaryUrl', $e->operation);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | exists() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_true_when_file_exists(): void
    {
        $this->mockFilesystem
            ->shouldReceive('exists')
            ->once()
            ->with('feeds/output.xml')
            ->andReturn(true);

        $result = $this->client->exists('feeds/output.xml');

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_file_does_not_exist(): void
    {
        $this->mockFilesystem
            ->shouldReceive('exists')
            ->once()
            ->with('feeds/missing.xml')
            ->andReturn(false);

        $result = $this->client->exists('feeds/missing.xml');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_throws_storage_exception_on_unable_to_check_existence(): void
    {
        $existenceException = UnableToCheckExistence::forLocation('feeds/output.xml');

        $this->mockFilesystem
            ->shouldReceive('exists')
            ->andThrow($existenceException);

        $this->expectException(StorageOperationFailedException::class);
        $this->expectExceptionMessage('Storage operation failed');

        $this->client->exists('feeds/output.xml');
    }

    #[Test]
    public function it_includes_correct_operation_in_exists_exception(): void
    {
        $this->mockFilesystem
            ->shouldReceive('exists')
            ->andThrow(new RuntimeException('Network error'));

        try {
            $this->client->exists('feeds/output.xml');
            $this->fail('Expected StorageOperationFailedException');
        } catch (StorageOperationFailedException $e) {
            $this->assertSame('exists', $e->operation);
            $this->assertSame('feeds/output.xml', $e->path);
        }
    }

    #[Test]
    public function it_logs_error_on_exists_failure(): void
    {
        $this->mockFilesystem
            ->shouldReceive('exists')
            ->andThrow(new RuntimeException('S3 unavailable'));

        $this->mockLogger
            ->shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['operation'] === 'exists'
                    && \str_contains($context['error'], 'S3 unavailable'));

        try {
            $this->client->exists('feeds/output.xml');
        } catch (StorageOperationFailedException) {
            // Expected
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Disk Configuration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_configured_disk(): void
    {
        $customFilesystem = Mockery::mock(FilesystemAdapter::class);
        $customFilesystem->shouldReceive('put')->once();

        $this->mockFilesystemFactory
            ->shouldReceive('disk')
            ->once()
            ->with('custom-s3')
            ->andReturn($customFilesystem);

        $client = new S3StorageClient(
            disk: 'custom-s3',
            filesystemFactory: $this->mockFilesystemFactory,
            logger: $this->mockLogger,
        );

        $client->put('path.txt', 'content');
    }
}
