<?php

declare(strict_types=1);

namespace App\Infrastructure\Feeds;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Application\Contracts\RemoteStorageInterface;
use App\Application\Feeds\ProductSearchFeedProcessingResult;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\MalformedFeedDataException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;
use XMLReader;

/**
 * Doofinder product search feed processor.
 *
 * Fetches Google Ads product feed, transforms XML by substituting
 * <title> elements with <d_title> values (display titles), and
 * uploads to cloud storage for Doofinder consumption.
 *
 * Memory-efficient design:
 * - XMLReader streams through source (doesn't load entire DOM)
 * - Output streams to temp file (avoids unbounded string growth)
 * - Validation happens on first item during single parse pass
 * - Explicit cleanup of large variables
 */
final readonly class DoofinderFeedProcessor implements ProductSearchFeedProcessorInterface
{
    private const string SERVICE_NAME = 'Doofinder Feed';
    private const int HTTP_TIMEOUT_SECONDS = 120;
    private const int MAX_REDIRECT_DEPTH = 5;

    public function __construct(
        private RemoteStorageInterface $storage,
        private LoggerInterface $logger,
        private DoofinderItemTransformer $itemTransformer,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException When HTTP client encounters unexpected errors (Guzzle internals, SSL, etc.)
     */
    public function process(string $sourceUrl, string $outputPath): ProductSearchFeedProcessingResult
    {
        $startTime = \microtime(true);

        $this->logger->info('Starting feed processing', [
            'service' => self::SERVICE_NAME,
            'source_url' => $sourceUrl,
        ]);

        $sourceXml = $this->fetchSourceFeed($sourceUrl);
        $tempFilePath = $this->transformFeedToTempFile($sourceXml);

        // Release source XML memory before reading temp file
        unset($sourceXml);

        try {
            // Read transformed content and upload
            $transformedXml = \file_get_contents($tempFilePath);

            if ($transformedXml === false) {
                throw new MalformedFeedDataException(
                    feedName: self::SERVICE_NAME,
                    reason: "Failed to read transformed feed from temp file: {$tempFilePath}",
                );
            }

            // Extract stats from temp file metadata (stored as JSON in first line comment)
            $stats = self::extractStatsFromTempFile($tempFilePath);

            $this->storage->put($outputPath, $transformedXml);

            // Release memory (file cleanup happens in finally)
            unset($transformedXml);
        } finally {
            // Always cleanup temp files, even on failure
            // safeUnlink is idempotent (stats file may already be deleted by extractStatsFromTempFile)
            self::safeUnlink($tempFilePath);
            self::safeUnlink($tempFilePath . '.stats');
        }

        $durationSeconds = \microtime(true) - $startTime;

        $this->logger->info('Feed processing completed', [
            'service' => self::SERVICE_NAME,
            'items_processed' => $stats['itemsProcessed'],
            'titles_substituted' => $stats['titlesSubstituted'],
            'duration_seconds' => \round($durationSeconds, 2),
        ]);

        return new ProductSearchFeedProcessingResult(
            itemsProcessed: $stats['itemsProcessed'],
            titlesSubstituted: $stats['titlesSubstituted'],
            durationSeconds: $durationSeconds,
        );
    }

    /**
     * Fetch the source feed XML from the given URL.
     *
     * Handles meta-refresh redirects (common with e-commerce platforms that serve
     * feeds via signed S3 URLs). If HTML with meta refresh is returned, extracts
     * the redirect URL and follows it.
     *
     * @throws ExternalServiceUnavailableException When feed cannot be fetched or redirect limit exceeded
     * @throws RuntimeException When HTTP client cannot be resolved (container misconfiguration)
     */
    private function fetchSourceFeed(string $sourceUrl, int $redirectDepth = 0): string
    {
        if ($redirectDepth >= self::MAX_REDIRECT_DEPTH) {
            $this->logger->error('Max redirect depth exceeded', [
                'service' => self::SERVICE_NAME,
                'url' => $sourceUrl,
                'max_depth' => self::MAX_REDIRECT_DEPTH,
            ]);

            throw new ExternalServiceUnavailableException(
                serviceName: self::SERVICE_NAME,
                retryAfter: 300,
            );
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get($sourceUrl);
            Assert::isInstanceOf($response, Response::class);

            if ($response->failed()) {
                $this->logger->error('Feed fetch failed', [
                    'service' => self::SERVICE_NAME,
                    'url' => $sourceUrl,
                    'status' => $response->status(),
                ]);

                throw new ExternalServiceUnavailableException(
                    serviceName: self::SERVICE_NAME,
                    retryAfter: 300,
                );
            }

            $body = $response->body();

            // Handle meta-refresh redirects (e.g., ShopWired serving via signed S3 URLs)
            $redirectUrl = self::extractMetaRefreshUrl($body);

            if ($redirectUrl !== null) {
                $this->logger->debug('Following meta-refresh redirect', [
                    'service' => self::SERVICE_NAME,
                    'original_url' => $sourceUrl,
                    'redirect_url' => $redirectUrl,
                ]);

                return $this->fetchSourceFeed($redirectUrl, $redirectDepth + 1);
            }

            return $body;
        } catch (ConnectionException $e) {
            $this->logger->error('Feed connection failed', [
                'service' => self::SERVICE_NAME,
                'url' => $sourceUrl,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException(
                serviceName: self::SERVICE_NAME,
                retryAfter: 300,
                previous: $e,
            );
        }
    }

    /**
     * Extract redirect URL from HTML meta-refresh tag.
     *
     * Matches: <meta http-equiv="refresh" content="0;url='https://...'" />
     */
    private static function extractMetaRefreshUrl(string $html): ?string
    {
        // Only check if this looks like HTML (not XML feed)
        if (!\str_starts_with(\mb_trim($html), '<!DOCTYPE') && !\str_starts_with(\mb_trim($html), '<html')) {
            return null;
        }

        // Match meta refresh: content="0;url='...'" or content="0;url=..."
        if (\preg_match('/http-equiv=["\']refresh["\'][^>]*content=["\'][^"\']*url=[\'"]?([^"\'>\s]+)/i', $html, $matches) === 1) {
            return \html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    /**
     * Transform feed XML to a temp file with streaming output.
     *
     * Single-pass processing:
     * - Validates first item structure (fail-fast)
     * - Streams transformed items to temp file (memory-efficient)
     * - Stores stats in a separate metadata file
     *
     * @return string Path to temp file containing transformed XML
     *
     * @throws MalformedFeedDataException When XML is invalid or missing required structure
     */
    private function transformFeedToTempFile(string $sourceXml): string
    {
        $reader = $this->createXmlReader($sourceXml);
        $tempPath = \sys_get_temp_dir() . '/doofinder-feed-' . Str::uuid()->toString() . '.xml';
        $handle = $this->openTempFile($tempPath, $reader);

        $itemsProcessed = 0;
        $titlesSubstituted = 0;
        $isFirstItem = true;

        try {
            while ($reader->read()) {
                $result = $this->processNode($reader, $handle, $isFirstItem);

                if ($result !== null) {
                    [$isFirstItem, $wasSubstituted] = $result;
                    $itemsProcessed++;

                    if ($wasSubstituted) {
                        $titlesSubstituted++;
                    }
                }
            }

            $this->validateFeedNotEmpty($isFirstItem);
        } catch (Throwable $e) {
            $this->cleanupOnError($handle, $reader, $tempPath);
            throw $this->wrapException($e);
        }

        \fclose($handle);
        $reader->close();

        self::writeProcessingStats($tempPath, $itemsProcessed, $titlesSubstituted);

        return $tempPath;
    }

    /**
     * Create XMLReader from source XML string.
     *
     * @throws MalformedFeedDataException When XML cannot be parsed
     */
    private function createXmlReader(string $sourceXml): XMLReader
    {
        try {
            return XMLReader::fromString($sourceXml, encoding: 'UTF-8');
        } catch (Throwable $e) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: 'Failed to parse XML: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Open temp file for writing, closing reader on failure.
     *
     * @return resource File handle
     *
     * @throws MalformedFeedDataException When temp file cannot be created
     */
    private function openTempFile(string $tempPath, XMLReader $reader): mixed
    {
        $handle = \fopen($tempPath, 'wb');

        if ($handle === false) {
            $reader->close();

            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: "Failed to create temp file for feed processing: {$tempPath}",
            );
        }

        return $handle;
    }

    /**
     * Process a single XML node during streaming.
     *
     * @param resource $handle File handle for output
     * @return array{0: bool, 1: bool}|null [isFirstItem, wasSubstituted] for items, null for other nodes
     *
     * @throws MalformedFeedDataException When item transformation fails
     */
    private function processNode(XMLReader $reader, mixed $handle, bool $isFirstItem): ?array
    {
        // Handle item elements specially (transformation + validation)
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'item') {
            return $this->processItemNode($reader, $handle, $isFirstItem);
        }

        // Write non-item nodes directly using match
        $this->writeNonItemNode($reader, $handle);

        return null;
    }

    /**
     * Process an item element: validate, transform, and write.
     *
     * @param resource $handle File handle for output
     * @return array{0: bool, 1: bool} [isFirstItem (always false after), wasSubstituted]
     *
     * @throws MalformedFeedDataException When item validation or transformation fails
     */
    private function processItemNode(XMLReader $reader, mixed $handle, bool $isFirstItem): array
    {
        $itemXml = $reader->readOuterXml();

        if ($itemXml === '') {
            return [$isFirstItem, false];
        }

        if ($isFirstItem) {
            $this->itemTransformer->validateFirstItem($itemXml);
        }

        [$transformedItem, $wasSubstituted] = $this->itemTransformer->transform($itemXml);
        \fwrite($handle, $transformedItem);

        // Skip to next sibling (readOuterXml doesn't advance cursor past element)
        $reader->next();

        return [false, $wasSubstituted];
    }

    /**
     * Write non-item XML node to output handle using match dispatch.
     *
     * @param resource $handle File handle for output
     */
    private function writeNonItemNode(XMLReader $reader, mixed $handle): void
    {
        // Match returns fwrite result (int|false) or null - intentionally unused
        $bytesWritten = match ($reader->nodeType) {
            XMLReader::ELEMENT => \fwrite($handle, self::getOpeningTag($reader)),
            XMLReader::END_ELEMENT => \fwrite($handle, "</{$reader->localName}>"),
            XMLReader::TEXT, XMLReader::CDATA => \fwrite($handle, \htmlspecialchars($reader->value, ENT_XML1 | ENT_QUOTES, 'UTF-8')),
            XMLReader::XML_DECLARATION => \fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"),
            default => null,
        };

        // Silence unused variable warning - we don't need the byte count
        unset($bytesWritten);
    }

    /**
     * Validate that the feed contained at least one item.
     *
     * @throws MalformedFeedDataException When feed is empty
     */
    private function validateFeedNotEmpty(bool $isFirstItem): void
    {
        if ($isFirstItem) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: 'Feed contains no items - cannot process empty feed',
            );
        }
    }

    /**
     * Clean up resources on error.
     *
     * @param resource $handle File handle to close
     */
    private function cleanupOnError(mixed $handle, XMLReader $reader, string $tempPath): void
    {
        \fclose($handle);
        $reader->close();
        self::safeUnlink($tempPath);
        self::safeUnlink($tempPath . '.stats');
    }

    /**
     * Wrap non-domain exceptions in MalformedFeedDataException.
     */
    private function wrapException(Throwable $e): MalformedFeedDataException
    {
        if ($e instanceof MalformedFeedDataException) {
            return $e;
        }

        return new MalformedFeedDataException(
            feedName: self::SERVICE_NAME,
            reason: "XML processing error: {$e->getMessage()}",
            previous: $e,
        );
    }

    /**
     * Write processing stats to a JSON file.
     *
     * @throws MalformedFeedDataException When stats cannot be encoded
     */
    private static function writeProcessingStats(string $tempPath, int $itemsProcessed, int $titlesSubstituted): void
    {
        $statsPath = $tempPath . '.stats';

        try {
            $statsJson = \json_encode(\compact('itemsProcessed', 'titlesSubstituted'), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            self::safeUnlink($tempPath);

            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: 'Failed to encode processing stats',
                previous: $e,
            );
        }

        \file_put_contents($statsPath, $statsJson);
    }

    /**
     * Extract processing stats from temp file metadata.
     *
     * @return array{itemsProcessed: int, titlesSubstituted: int}
     *
     * @throws MalformedFeedDataException When stats file cannot be read or parsed
     */
    private static function extractStatsFromTempFile(string $tempFilePath): array
    {
        $statsPath = $tempFilePath . '.stats';
        $statsJson = \file_get_contents($statsPath);

        if ($statsJson === false) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: "Failed to read stats file: {$statsPath}",
            );
        }

        try {
            /** @var array{itemsProcessed: int, titlesSubstituted: int} $stats */
            $stats = \json_decode($statsJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: "Failed to decode stats JSON: {$statsPath}",
                previous: $e,
            );
        }

        // Cleanup stats file
        self::safeUnlink($statsPath);

        return $stats;
    }

    /**
     * Safely delete a file if it exists.
     */
    private static function safeUnlink(string $path): void
    {
        if (\file_exists($path)) {
            \unlink($path);
        }
    }

    /**
     * Build opening tag string from XMLReader position.
     */
    private static function getOpeningTag(XMLReader $reader): string
    {
        $tag = '<' . $reader->localName;

        if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
                $tag .= ' ' . $reader->name . '="' . \htmlspecialchars($reader->value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
            }
            $reader->moveToElement();
        }

        if ($reader->isEmptyElement) {
            $tag .= '/>';
        } else {
            $tag .= '>';
        }

        return $tag;
    }
}
