<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Controllers;

use App\Presentation\Http\Controllers\FeedController;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * FeedController Unit Tests.
 *
 * Tests the feed URL routing:
 * - Config matching (prefix + GUID)
 * - File existence checks
 * - Signed URL generation and redirect
 * - Error handling for missing feeds
 */
#[CoversClass(FeedController::class)]
final class FeedControllerTest extends TestCase
{
    private FilesystemManager&MockInterface $mockFilesystemManager;
    private FilesystemAdapter&MockInterface $mockDisk;
    private FeedController $controller;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockFilesystemManager = Mockery::mock(FilesystemManager::class);
        $this->mockDisk = Mockery::mock(FilesystemAdapter::class);

        $this->mockFilesystemManager
            ->shouldReceive('disk')
            ->andReturn($this->mockDisk)
            ->byDefault();

        $this->controller = new FeedController($this->mockFilesystemManager);

        // Default valid config
        Config::set('feeds', [
            'doofinder' => [
                'public_prefix' => 'doofinder',
                'public_guid' => 'abc123def456',
                'storage_disk' => 's3',
                'storage_path' => 'feeds/doofinder-processed.xml',
                'signed_url_expiry_minutes' => 60,
            ],
        ]);

        // Freeze time for predictable URL expiry
        Carbon::setTestNow('2024-12-05 12:00:00');
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_redirects_to_signed_s3_url(): void
    {
        $expectedUrl = 'https://s3.example.com/feeds/doofinder-processed.xml?signature=xyz';

        $this->mockDisk
            ->shouldReceive('exists')
            ->with('feeds/doofinder-processed.xml')
            ->andReturn(true);

        $this->mockDisk
            ->shouldReceive('temporaryUrl')
            ->once()
            ->andReturn($expectedUrl);

        $response = $this->controller->show('doofinder', 'abc123def456');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame($expectedUrl, $response->getTargetUrl());
    }

    #[Test]
    public function it_uses_correct_disk_from_config(): void
    {
        $this->mockFilesystemManager
            ->shouldReceive('disk')
            ->once()
            ->with('s3')
            ->andReturn($this->mockDisk);

        $this->mockDisk->shouldReceive('exists')->andReturn(true);
        $this->mockDisk->shouldReceive('temporaryUrl')->andReturn('https://url');

        $this->controller->show('doofinder', 'abc123def456');
    }

    #[Test]
    public function it_uses_custom_expiry_minutes_from_config(): void
    {
        Config::set('feeds.doofinder.signed_url_expiry_minutes', 120);

        $this->mockDisk->shouldReceive('exists')->andReturn(true);

        $this->mockDisk
            ->shouldReceive('temporaryUrl')
            ->once()
            ->withArgs(static function (string $path, DateTimeInterface $expiry): bool {
                // Should be 120 minutes from now (2024-12-05 12:00:00 + 120 min = 14:00:00)
                return $expiry->format('Y-m-d H:i:s') === '2024-12-05 14:00:00';
            })
            ->andReturn('https://url');

        $this->controller->show('doofinder', 'abc123def456');
    }

    #[Test]
    public function it_uses_default_expiry_when_not_configured(): void
    {
        Config::set('feeds.doofinder.signed_url_expiry_minutes', null);

        $this->mockDisk->shouldReceive('exists')->andReturn(true);

        $this->mockDisk
            ->shouldReceive('temporaryUrl')
            ->once()
            ->withArgs(static function (string $path, DateTimeInterface $expiry): bool {
                // Default is 1440 minutes (24 hours) from now
                return $expiry->format('Y-m-d H:i:s') === '2024-12-06 12:00:00';
            })
            ->andReturn('https://url');

        $this->controller->show('doofinder', 'abc123def456');
    }

    /*
    |--------------------------------------------------------------------------
    | Feed Not Found Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_not_found_for_invalid_prefix(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Feed not found');

        $this->controller->show('invalid-prefix', 'abc123def456');
    }

    #[Test]
    public function it_throws_not_found_for_invalid_guid(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Feed not found');

        $this->controller->show('doofinder', 'wrong-guid');
    }

    #[Test]
    public function it_throws_not_found_when_both_prefix_and_guid_wrong(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Feed not found');

        $this->controller->show('wrong', 'wrong');
    }

    #[Test]
    public function it_throws_not_found_when_config_missing_storage_disk(): void
    {
        Config::set('feeds', [
            'doofinder' => [
                'public_prefix' => 'doofinder',
                'public_guid' => 'abc123def456',
                // storage_disk missing
                'storage_path' => 'feeds/output.xml',
            ],
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Feed not found');

        $this->controller->show('doofinder', 'abc123def456');
    }

    #[Test]
    public function it_throws_not_found_when_config_missing_storage_path(): void
    {
        Config::set('feeds', [
            'doofinder' => [
                'public_prefix' => 'doofinder',
                'public_guid' => 'abc123def456',
                'storage_disk' => 's3',
                // storage_path missing
            ],
        ]);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Feed not found');

        $this->controller->show('doofinder', 'abc123def456');
    }

    #[Test]
    public function it_throws_not_found_when_file_does_not_exist(): void
    {
        $this->mockDisk
            ->shouldReceive('exists')
            ->with('feeds/doofinder-processed.xml')
            ->andReturn(false);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Feed not yet generated');

        $this->controller->show('doofinder', 'abc123def456');
    }

    /*
    |--------------------------------------------------------------------------
    | Multiple Feeds Configuration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_matches_correct_feed_from_multiple(): void
    {
        Config::set('feeds', [
            'doofinder' => [
                'public_prefix' => 'doofinder',
                'public_guid' => 'guid1',
                'storage_disk' => 's3',
                'storage_path' => 'feeds/doofinder.xml',
            ],
            'google' => [
                'public_prefix' => 'google',
                'public_guid' => 'guid2',
                'storage_disk' => 'gcs',
                'storage_path' => 'feeds/google.xml',
            ],
        ]);

        $this->mockFilesystemManager
            ->shouldReceive('disk')
            ->with('gcs')
            ->andReturn($this->mockDisk);

        $this->mockDisk->shouldReceive('exists')->with('feeds/google.xml')->andReturn(true);
        $this->mockDisk->shouldReceive('temporaryUrl')->andReturn('https://google-url');

        $response = $this->controller->show('google', 'guid2');

        $this->assertSame('https://google-url', $response->getTargetUrl());
    }

    #[Test]
    public function it_skips_non_array_feed_configs(): void
    {
        Config::set('feeds', [
            'invalid' => 'not an array',
            'doofinder' => [
                'public_prefix' => 'doofinder',
                'public_guid' => 'abc123def456',
                'storage_disk' => 's3',
                'storage_path' => 'feeds/doofinder-processed.xml',
            ],
        ]);

        $this->mockDisk->shouldReceive('exists')->andReturn(true);
        $this->mockDisk->shouldReceive('temporaryUrl')->andReturn('https://url');

        $response = $this->controller->show('doofinder', 'abc123def456');

        $this->assertSame('https://url', $response->getTargetUrl());
    }
}
