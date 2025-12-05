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
use JsonException;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
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

    public function __construct(
        private RemoteStorageInterface $storage,
        private LoggerInterface $logger,
    ) {}

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

        // Cleanup
        unset($transformedXml);
        \unlink($tempFilePath);

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
     * @throws ExternalServiceUnavailableException When feed cannot be fetched
     */
    private function fetchSourceFeed(string $sourceUrl): string
    {
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

            return $response->body();
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
        try {
            $reader = XMLReader::fromString($sourceXml, encoding: 'UTF-8');
        } catch (Throwable $e) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: 'Failed to parse XML: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $tempPath = \sys_get_temp_dir() . '/doofinder-feed-' . \uniqid('', true) . '.xml';
        $statsPath = $tempPath . '.stats';
        $handle = \fopen($tempPath, 'wb');

        if ($handle === false) {
            $reader->close();

            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: "Failed to create temp file for feed processing: {$tempPath}",
            );
        }

        $itemsProcessed = 0;
        $titlesSubstituted = 0;
        $isFirstItem = true;

        try {
            while ($reader->read()) {
                if (($reader->nodeType === XMLReader::ELEMENT) && ($reader->localName === 'item')) {
                    $itemXml = $reader->readOuterXml();

                    if ($itemXml === '') {
                        continue;
                    }

                    // Validate first item structure before processing any items
                    if ($isFirstItem) {
                        $this->validateFirstItem($itemXml);
                        $isFirstItem = false;
                    }

                    [$transformedItem, $wasSubstituted] = self::transformItem($itemXml);
                    \fwrite($handle, $transformedItem);
                    $itemsProcessed++;

                    if ($wasSubstituted) {
                        $titlesSubstituted++;
                    }

                    // Skip to next sibling (readOuterXml doesn't advance cursor past element)
                    $reader->next();
                } elseif ($reader->nodeType === XMLReader::ELEMENT) {
                    \fwrite($handle, self::getOpeningTag($reader));
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                    \fwrite($handle, "</{$reader->localName}>");
                } elseif (($reader->nodeType === XMLReader::TEXT) || ($reader->nodeType === XMLReader::CDATA)) {
                    \fwrite($handle, \htmlspecialchars($reader->value, ENT_XML1, 'UTF-8'));
                } elseif ($reader->nodeType === XMLReader::XML_DECLARATION) {
                    \fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
                }
            }

            // Validate feed wasn't empty
            if ($isFirstItem) {
                throw new MalformedFeedDataException(
                    feedName: self::SERVICE_NAME,
                    reason: 'Feed contains no items - cannot process empty feed',
                );
            }
        } catch (Throwable $e) {
            \fclose($handle);
            $reader->close();
            self::safeUnlink($tempPath);
            self::safeUnlink($statsPath);

            if ($e instanceof MalformedFeedDataException) {
                throw $e;
            }

            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: "XML processing error: {$e->getMessage()}",
                previous: $e,
            );
        }

        \fclose($handle);
        $reader->close();

        // Write stats to separate file for retrieval
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

        return $tempPath;
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
     * Validate the first item has required title and d_title elements.
     *
     * @throws MalformedFeedDataException When required elements are missing
     */
    private function validateFirstItem(string $itemXml): void
    {
        try {
            $item = new SimpleXMLElement($itemXml);
        } catch (Throwable $e) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: 'First item XML is malformed: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $namespaces = $item->getNamespaces(true);
        $gNamespace = $namespaces['g'] ?? null;

        if ($gNamespace !== null) {
            $gChildren = $item->children($gNamespace);

            if ($gChildren === null) {
                throw new MalformedFeedDataException(
                    feedName: self::SERVICE_NAME,
                    reason: 'First item has no Google namespace elements - expected g:title and g:d_title',
                );
            }

            if (!isset($gChildren->title)) {
                throw new MalformedFeedDataException(
                    feedName: self::SERVICE_NAME,
                    reason: 'First item missing required g:title element',
                );
            }

            if (!isset($gChildren->d_title)) {
                throw new MalformedFeedDataException(
                    feedName: self::SERVICE_NAME,
                    reason: 'First item missing required g:d_title element - feed may not be configured for title substitution',
                );
            }

            $this->logger->debug('Feed structure validated', [
                'service' => self::SERVICE_NAME,
                'namespace' => 'g',
                'sample_title' => (string) $gChildren->title,
                'sample_d_title' => (string) $gChildren->d_title,
            ]);
        } else {
            // Non-namespaced validation
            if (!isset($item->title)) {
                throw new MalformedFeedDataException(
                    feedName: self::SERVICE_NAME,
                    reason: 'First item missing required title element',
                );
            }

            if (!isset($item->d_title)) {
                throw new MalformedFeedDataException(
                    feedName: self::SERVICE_NAME,
                    reason: 'First item missing required d_title element - feed may not be configured for title substitution',
                );
            }

            $this->logger->debug('Feed structure validated', [
                'service' => self::SERVICE_NAME,
                'namespace' => 'none',
                'sample_title' => (string) $item->title,
                'sample_d_title' => (string) $item->d_title,
            ]);
        }
    }

    /**
     * Transform a single item element.
     *
     * @return array{0: string, 1: bool} [transformedXml, wasSubstituted]
     */
    private static function transformItem(string $itemXml): array
    {
        try {
            $item = new SimpleXMLElement($itemXml);
        } catch (Throwable $e) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: "Invalid item XML: {$e->getMessage()}",
                previous: $e,
            );
        }

        $wasSubstituted = false;

        // Check for g:d_title (Google namespace) or d_title
        $namespaces = $item->getNamespaces(true);
        $gNamespace = $namespaces['g'] ?? null;

        if ($gNamespace !== null) {
            $gChildren = $item->children($gNamespace);

            if (($gChildren !== null) && isset($gChildren->d_title, $gChildren->title)) {
                $displayTitle = (string) $gChildren->d_title;

                if ($displayTitle !== '') {
                    $gChildren->title = $displayTitle;
                    $wasSubstituted = true;
                }
            }
        } elseif (isset($item->d_title, $item->title)) {
            // Try non-namespaced
            $displayTitle = (string) $item->d_title;

            if ($displayTitle !== '') {
                $item->title = $displayTitle;
                $wasSubstituted = true;
            }
        }

        $result = $item->asXML();

        if ($result === false) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: 'Failed to serialize transformed item',
            );
        }

        // Remove XML declaration that SimpleXML adds
        $result = \preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $result);

        return [$result ?? '', $wasSubstituted];
    }

    /**
     * Build opening tag string from XMLReader position.
     */
    private static function getOpeningTag(XMLReader $reader): string
    {
        $tag = '<' . $reader->localName;

        if ($reader->hasAttributes) {
            while ($reader->moveToNextAttribute()) {
                $tag .= ' ' . $reader->name . '="' . \htmlspecialchars($reader->value, ENT_XML1, 'UTF-8') . '"';
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
