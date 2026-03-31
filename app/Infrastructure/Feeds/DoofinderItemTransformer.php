<?php

declare(strict_types=1);

namespace App\Infrastructure\Feeds;

use App\Domain\Exceptions\Data\MalformedFeedDataException;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Throwable;

/**
 * Transforms individual Doofinder feed items.
 *
 * Handles title substitution: replaces <title> with <d_title> content.
 * Supports both non-namespaced and Google-namespaced (g:) elements.
 */
final readonly class DoofinderItemTransformer
{
    private const string SERVICE_NAME = 'Doofinder Feed';

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Validate the first item has required title and d_title elements.
     *
     * @throws MalformedFeedDataException When required elements are missing
     */
    public function validateFirstItem(string $itemXml): void
    {
        $item = self::parseItemXml($itemXml);
        $elements = $this->resolveTitleElements($item);

        if ($elements !== null) {
            $this->logger->debug('Feed structure validated', [
                'service' => self::SERVICE_NAME,
                'namespace' => $elements['namespace'],
                'sample_title' => $elements['title'],
                'sample_d_title' => $elements['dTitle'],
            ]);

            return;
        }

        // Report what's missing for debugging
        self::throwMissingElementException($item);
    }

    /**
     * Transform a single item element.
     *
     * Substitutes <title> content with <d_title> content if both exist.
     *
     * @return array{0: string, 1: bool} [transformedXml, wasSubstituted]
     *
     * @throws MalformedFeedDataException When item XML is invalid or cannot be serialized
     */
    public function transform(string $itemXml): array
    {
        $item = self::parseItemXml($itemXml);
        $wasSubstituted = $this->substituteTitle($item);

        return [$this->serializeItem($item), $wasSubstituted];
    }

    /**
     * Parse item XML string into SimpleXMLElement.
     *
     * @throws MalformedFeedDataException When XML is malformed
     */
    private static function parseItemXml(string $itemXml): SimpleXMLElement
    {
        try {
            return new SimpleXMLElement($itemXml);
        } catch (Throwable $e) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: "Invalid item XML: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Resolve title and d_title elements, checking both namespaced and non-namespaced.
     *
     * @return array{namespace: string, title: string, dTitle: string, titleElement: SimpleXMLElement, dTitleElement: SimpleXMLElement}|null
     */
    private function resolveTitleElements(SimpleXMLElement $item): ?array
    {
        if (isset($item->title, $item->d_title)) {
            return [
                'namespace' => 'none',
                'title' => (string) $item->title,
                'dTitle' => (string) $item->d_title,
                'titleElement' => $item->title,
                'dTitleElement' => $item->d_title,
            ];
        }

        return self::resolveGoogleNamespacedTitleElements($item);
    }

    /**
     * Check for title elements under the Google Ads namespace (g:title, g:d_title).
     *
     * @return array{namespace: string, title: string, dTitle: string, titleElement: SimpleXMLElement, dTitleElement: SimpleXMLElement}|null
     */
    private static function resolveGoogleNamespacedTitleElements(SimpleXMLElement $item): ?array
    {
        $gNamespace = ($item->getNamespaces(true))['g'] ?? null;
        if ($gNamespace === null) {
            return null;
        }

        $gChildren = $item->children($gNamespace);
        if (($gChildren === null) || ! isset($gChildren->title, $gChildren->d_title)) {
            return null;
        }

        return [
            'namespace' => 'g',
            'title' => (string) $gChildren->title,
            'dTitle' => (string) $gChildren->d_title,
            'titleElement' => $gChildren->title,
            'dTitleElement' => $gChildren->d_title,
        ];
    }

    /**
     * Substitute title with d_title content.
     *
     * @return bool Whether substitution occurred
     */
    private function substituteTitle(SimpleXMLElement $item): bool
    {
        $elements = $this->resolveTitleElements($item);

        if ($elements === null) {
            return false;
        }

        $displayTitle = $elements['dTitle'];

        if ($displayTitle === '') {
            return false;
        }

        // Modify the title element in-place
        $elements['titleElement'][0] = $displayTitle;

        return true;
    }

    /**
     * Serialize item back to XML string.
     *
     * @throws MalformedFeedDataException When serialization fails
     */
    private function serializeItem(SimpleXMLElement $item): string
    {
        $result = $item->asXML();

        if ($result === false) {
            throw new MalformedFeedDataException(
                feedName: self::SERVICE_NAME,
                reason: 'Failed to serialize transformed item',
            );
        }

        // Remove XML declaration that SimpleXML adds
        $result = \preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $result);

        return $result ?? '';
    }

    /**
     * Throw appropriate exception based on which elements are missing.
     *
     * @throws MalformedFeedDataException Always throws
     */
    private static function throwMissingElementException(SimpleXMLElement $item): never
    {
        $hasTitle = isset($item->title);
        /** @noinspection PhpVariableNamingConventionInspection */
        $hasDTitle = isset($item->d_title);

        $reason = match (true) {
            ! $hasTitle && ! $hasDTitle => 'First item missing both title and d_title elements',
            ! $hasTitle => 'First item missing required title element',
            default => 'First item missing required d_title element - feed may not be configured for title substitution',
        };

        throw new MalformedFeedDataException(feedName: self::SERVICE_NAME, reason: $reason);
    }
}
