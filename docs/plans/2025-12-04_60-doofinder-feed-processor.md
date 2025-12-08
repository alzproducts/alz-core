# Google Ads Product Feed Processor for DooFinder

## Overview

Process a Google Ads product feed daily, replacing `<title>` with `<d_title>` values, and serve via our own branded URL that redirects to S3 storage.

## Requirements Summary

- **Source**: `https://www.alzproducts.co.uk/feed/products` (redirects to ShopWired S3)
- **Transform**: Replace `<title>` content with `<d_title>` content for each `<item>`
- **Public URL**: `https://{our-domain}/feeds/doofinder-{32-char-guid}.xml` (hybrid: readable prefix + GUID obscurity)
- **Storage**: S3 private bucket, served via signed URLs
- **Schedule**: Daily at 1:00 AM UK time (handles BST/GMT)
- **Size**: 1,000-10,000 products - use streaming XML for memory efficiency

## Dependencies

```bash
composer require league/flysystem-aws-s3-v3  # Required for S3 support
```

## Architecture

### Clean Architecture Layer Placement

```
Presentation/
├── Http/
│   └── Controllers/
│       └── FeedController.php             # Serves feed URL, redirects to S3
├── Jobs/
│   └── ProcessProductFeedJob.php          # Scheduled entry point

Application/
├── Contracts/
│   └── ProductFeedProcessorInterface.php  # Interface for feed processing
├── ProductFeed/
│   └── ProcessProductFeedUseCase.php      # Orchestrates fetch → transform → upload

Infrastructure/
├── ProductFeed/
│   ├── ProductFeedProcessor.php           # Implements streaming XML transform
│   └── ProductFeedHttpClient.php          # Fetches source feed (handles redirects)
```

### New/Modified Files

| File | Action | Purpose |
|------|--------|---------|
| `config/filesystems.php` | Modify | Add S3 disk configuration |
| `config/feeds.php` | Create | Feed settings (source URL, S3 paths) |
| `routes/web.php` or `routes/api.php` | Modify | Add public feed route |
| `routes/console.php` | Modify | Add scheduled job |
| `app/Presentation/Http/Controllers/FeedController.php` | Create | Redirect to S3 signed URL |
| `app/Presentation/Jobs/ProcessProductFeedJob.php` | Create | Scheduled job |
| `app/Presentation/Console/Commands/ProcessDoofinderFeedCommand.php` | Create | Manual trigger |
| `app/Application/Contracts/ProductFeedProcessorInterface.php` | Create | Interface |
| `app/Application/ProductFeed/ProcessProductFeedUseCase.php` | Create | Use case |
| `app/Application/ProductFeed/ProcessingStats.php` | Create | Value object |
| `app/Infrastructure/ProductFeed/ProductFeedProcessor.php` | Create | XML streaming processor |
| `app/Infrastructure/ProductFeed/ProductFeedHttpClient.php` | Create | HTTP client for feed fetch |

---

## Implementation Details

### 1. Configuration (`config/feeds.php`)

```php
return [
    'doofinder' => [
        'source_url' => env('DOOFINDER_FEED_SOURCE_URL', 'https://www.alzproducts.co.uk/feed/products'),
        'storage_disk' => env('FEEDS_STORAGE_DISK', 's3'),
        'storage_path' => 'feeds/doofinder-processed.xml', // Internal S3 path (different from public slug)
        'public_prefix' => 'doofinder', // Route: /feeds/doofinder-{guid}.xml
        'public_guid' => env('DOOFINDER_FEED_GUID', 'a1b2c3d4e5f6789012345678abcdef12'), // Generate once, keep permanent
        'signed_url_expiry_minutes' => 1440, // 24 hours - allows for crawler caching
    ],
];
```

**Note:** Generate GUID once with: `php -r "echo bin2hex(random_bytes(16));"`

### 2. S3 Disk Configuration (`config/filesystems.php`)

Add to `disks` array:

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'eu-west-2'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'visibility' => 'private', // Private - we serve via signed URLs
],
```

### 3. Public Feed Route & Controller

**Route** (`routes/web.php`):

```php
// Matches: /feeds/doofinder-a1b2c3d4e5f6789012345678abcdef12.xml
Route::get('/feeds/{prefix}-{guid}.xml', [FeedController::class, 'show'])
    ->name('feeds.show')
    ->where(['prefix' => '[a-z0-9]+', 'guid' => '[a-f0-9]{32}']);
```

**Controller**:

```php
final class FeedController extends Controller
{
    public function show(string $prefix, string $guid): RedirectResponse
    {
        // Find feed config matching both prefix and GUID
        $feedConfig = collect(config('feeds'))
            ->first(fn ($feed) =>
                ($feed['public_prefix'] ?? null) === $prefix &&
                ($feed['public_guid'] ?? null) === $guid
            );

        if (! $feedConfig) {
            abort(404, 'Feed not found');
        }

        $disk = Storage::disk($feedConfig['storage_disk']);

        if (! $disk->exists($feedConfig['storage_path'])) {
            abort(404, 'Feed not yet generated');
        }

        // Generate temporary signed URL (24 hours default)
        $signedUrl = $disk->temporaryUrl(
            $feedConfig['storage_path'],
            now()->addMinutes($feedConfig['signed_url_expiry_minutes'] ?? 1440)
        );

        return redirect($signedUrl);
    }
}
```

**Benefits of this approach:**
- Our URL is permanent: `https://our-domain.com/feeds/doofinder-{guid}.xml`
- GUID provides obscurity - URL not guessable
- S3 object name is internal detail
- Signed URLs (24h) prevent direct S3 access/hotlinking
- Can add rate limiting, auth, logging at controller level
- Easy to add more feeds later (different prefix + GUID)

### 4. Streaming XML Processor Strategy

**Context**: DooFinder site search only reads `<title>` but we need `<d_title>` (display title) shown in search results. Transform the feed to substitute.

**Challenge**: XMLReader is forward-only, can't know if `<d_title>` exists until after `<title>` is encountered.

**Solution**: Buffer each `<item>` completely before writing:

```
┌─────────────────────────────────────────────────────────┐
│  Source Feed                    Transformed Feed        │
│  ───────────                    ────────────────        │
│  <item>                    ──►  <item>                  │
│    <title>Original</title>      <title>Display</title>  │  ← substituted
│    <d_title>Display</d_title>   <d_title>Display</d_title>
│    <g:price>...</g:price>       <g:price>...</g:price>  │
│  </item>                        </item>                 │
└─────────────────────────────────────────────────────────┘
```

**Algorithm**:
1. **Fetch** source feed via HTTP client (follows redirects) → temp file
2. **Open** temp file with XMLReader, output file with XMLWriter
3. **Stream** non-item nodes directly to output
4. **Buffer** each `<item>` element completely (using `XMLReader::readOuterXml()`)
5. **Parse** buffered item with SimpleXML (small, safe for single item)
6. **Substitute** `<title>` with `<d_title>` value (if `<d_title>` exists)
7. **Write** modified item XML to output
8. **Upload** completed output to S3 (atomic: temp key → final key)

```php
// Key processing loop (simplified)
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'item') {
        $itemXml = $reader->readOuterXml();  // Buffer entire <item>
        $item = new SimpleXMLElement($itemXml);

        if (isset($item->d_title)) {
            $item->title = (string) $item->d_title;  // Substitute
        }

        $writer->writeRaw($item->asXML());
        continue;
    }
    // Copy other nodes directly...
}
```

**Memory Profile**: Only one `<item>` in memory at a time (~1-5KB per item) + fixed overhead.

### 5. Scheduled Job (`routes/console.php`)

```php
Schedule::job(new ProcessProductFeedJob())
    ->dailyAt('01:00')
    ->timezone('Europe/London')  // Handles BST/GMT automatically
    ->onOneServer()
    ->withoutOverlapping(30);    // 30 min timeout for large feeds
```

### 6. Job Structure

```php
final class ProcessProductFeedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public function handle(ProcessProductFeedUseCase $useCase): void
    {
        $useCase->execute();
    }

    public function failed(Throwable $exception): void
    {
        Log::critical('Product feed processing failed permanently', [
            'exception' => $exception->getMessage(),
        ]);
    }
}
```

### 7. Application Layer Types

**Interface** (`Application/Contracts/ProductFeedProcessorInterface.php`):

```php
interface ProductFeedProcessorInterface
{
    public function process(string $sourceUrl, string $outputPath, string $disk): ProcessingStats;
}
```

**Value Object** (`Application/ProductFeed/ProcessingStats.php`):

```php
final readonly class ProcessingStats
{
    public function __construct(
        public int $itemsProcessed,
        public int $itemsSkipped,  // Items missing <d_title>
        public float $durationSeconds,
    ) {}
}
```

**Use Case** (`Application/ProductFeed/ProcessProductFeedUseCase.php`):

```php
final readonly class ProcessProductFeedUseCase
{
    public function __construct(
        private ProductFeedProcessorInterface $processor,
        private LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        $config = config('feeds.doofinder');

        $this->logger->info('Starting DooFinder feed processing', [
            'source' => $config['source_url'],
            'output' => $config['storage_path'],
        ]);

        $stats = $this->processor->process(
            sourceUrl: $config['source_url'],
            outputPath: $config['storage_path'],
            disk: $config['storage_disk'],
        );

        $this->logger->info('DooFinder feed processing completed', [
            'items_processed' => $stats->itemsProcessed,
            'items_skipped' => $stats->itemsSkipped,
            'duration_seconds' => $stats->durationSeconds,
        ]);
    }
}
```

---

## Error Handling

| Scenario | Handling |
|----------|----------|
| Source feed unavailable | Retry with backoff (job retries) |
| Source feed malformed XML | Log error, throw `InvalidApiResponseException`, fail job |
| S3 upload fails | Retry with backoff |
| Missing `<d_title>` in item | Keep original `<title>` unchanged, log warning |

---

## Testing Strategy

### Unit Tests
- `ProductFeedProcessorTest`: Mock XMLReader, verify title substitution logic
- `ProcessProductFeedUseCaseTest`: Mock processor interface, verify orchestration

### Integration Tests
- `ProductFeedProcessorIntegrationTest`: Use fixture XML file, verify output
- Test edge cases: missing `<d_title>`, empty feed, malformed XML

### Manual Testing
- Generate GUID, configure S3, run command manually
- Verify output XML structure and public accessibility

---

## Artisan Command (for manual runs)

```php
// app/Presentation/Console/Commands/ProcessDoofinderFeedCommand.php
final class ProcessDoofinderFeedCommand extends Command
{
    protected $signature = 'feed:process-doofinder';
    protected $description = 'Process Google Ads feed for DooFinder (substitute titles)';

    public function handle(ProcessProductFeedUseCase $useCase): int
    {
        $this->info('Processing DooFinder feed...');
        $useCase->execute();
        $this->info('Done.');
        return self::SUCCESS;
    }
}
```

---

## Environment Variables Required

```env
# AWS S3 Configuration
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=eu-west-2
AWS_BUCKET=alz-feeds

# DooFinder Feed Configuration
DOOFINDER_FEED_SOURCE_URL=https://www.alzproducts.co.uk/feed/products  # Optional, has default
DOOFINDER_FEED_GUID=<generate-once-with: php -r "echo bin2hex(random_bytes(16));">
```

---

## Implementation Order

1. **Dependencies**: `composer require league/flysystem-aws-s3-v3`
2. **Config**: Add `config/feeds.php` and S3 disk to `config/filesystems.php`
3. **Route**: Add feed route to `routes/web.php`
4. **Presentation**: Create `FeedController`, job, and artisan command
5. **Application**: Create interface, ProcessingStats DTO, and use case
6. **Infrastructure**: Create HTTP client and XML processor
7. **Scheduling**: Add to `routes/console.php`
8. **Tests**: Unit and integration tests
9. **Deploy**: Configure AWS env vars, generate GUID, run `feed:process-doofinder` manually

---

## Summary

**What this does**: Transforms a Google Ads product feed for DooFinder consumption by substituting `<title>` with `<d_title>` values.

**Public URL**: `https://{domain}/feeds/doofinder-{32-char-guid}.xml`

**Schedule**: Daily at 1:00 AM UK time

**Key decisions**:
- Hybrid URL format (prefix + GUID) balances readability with obscurity
- 24-hour signed URLs accommodate crawler caching
- Per-item buffering enables memory-efficient streaming with title substitution
