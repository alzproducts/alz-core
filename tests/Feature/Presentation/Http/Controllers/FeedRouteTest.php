<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Presentation\Http\Controllers\FeedController;
use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Router-level coverage for the feeds.show route: the URL constraints, the signed-URL
 * redirect, and the stateless contract (routes/web.php is registered outside the 'web'
 * middleware group, so no session cookie may be issued).
 */
#[CoversClass(FeedController::class)]
final class FeedRouteTest extends TestCase
{
    private const string FEED_DISK = 's3';

    private const string FEED_PATH = 'feeds/doofinder-processed.xml';

    /**
     * The route constrains {guid} to [a-f0-9]{32} — a shorter value 404s at the router.
     */
    private const string FEED_GUID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string UNKNOWN_GUID = 'ffffffffffffffffffffffffffffffff';

    private const string SIGNED_URL = 'https://s3.example.test/feeds/doofinder-processed.xml?signature=test';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // DOOFINDER_FEED_GUID is unset under test, so public_guid is null and every GUID
        // would 404 in FeedController::matchFeedConfig() before reaching storage.
        Config::set('feeds.doofinder.public_guid', self::FEED_GUID);
        Config::set('feeds.doofinder.storage_disk', self::FEED_DISK);
        Config::set('feeds.doofinder.storage_path', self::FEED_PATH);

        Storage::fake(self::FEED_DISK);

        $signedUrl = self::SIGNED_URL;

        Storage::disk(self::FEED_DISK)->buildTemporaryUrlsUsing(
            function (string $path, DateTimeInterface $expiration, array $options) use ($signedUrl): string {
                // temporaryUrl() rebinds this callback to the FilesystemAdapter and PHP cannot
                // bind an instance to a static closure, so the closure must stay non-static —
                // the $this read below is what keeps Pint's static_lambda rule off it.
                \assert($this instanceof FilesystemAdapter);

                return $signedUrl;
            },
        );

        // The controller 404s on a missing file before it ever builds a temporary URL.
        Storage::disk(self::FEED_DISK)->put(self::FEED_PATH, '<?xml version="1.0"?><rss/>');
    }

    #[Test]
    public function valid_prefix_and_guid_redirect_to_the_signed_storage_url(): void
    {
        $response = $this->get('/feeds/doofinder-' . self::FEED_GUID . '.xml');

        $response->assertRedirect(self::SIGNED_URL);
    }

    #[Test]
    public function unknown_guid_returns_not_found(): void
    {
        $response = $this->get('/feeds/doofinder-' . self::UNKNOWN_GUID . '.xml');

        $response->assertNotFound();
    }

    #[Test]
    public function feed_response_carries_no_cookies(): void
    {
        $response = $this->get('/feeds/doofinder-' . self::FEED_GUID . '.xml');

        $response->assertHeaderMissing('Set-Cookie');
    }
}
