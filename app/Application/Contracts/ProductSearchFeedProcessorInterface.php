<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Feeds\ProductSearchFeedProcessingResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedFeedDataException;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;

/**
 * Contract for product search feed transformation processing.
 *
 * This interface defines the boundary between Application and Infrastructure
 * for XML feed transformation operations. Implementation handles HTTP fetching,
 * XML streaming, transformation, and storage upload.
 */
interface ProductSearchFeedProcessorInterface
{
    /**
     * Process a product search feed: fetch, transform, and upload to storage.
     *
     * Fetches the source feed from the given URL, transforms the XML content
     * (e.g., substituting element values), and uploads the result to storage.
     * Storage disk is configured at construction time via dependency injection.
     *
     * @param string $sourceUrl  URL of the source feed to fetch
     * @param string $outputPath Path within the storage for the output file
     *
     * @return ProductSearchFeedProcessingResult Statistics about the processing operation
     *
     * @throws ExternalServiceUnavailableException When source feed is unreachable
     * @throws MalformedFeedDataException When source feed XML is malformed or unparseable
     * @throws StorageOperationFailedException When upload to storage fails
     */
    public function process(string $sourceUrl, string $outputPath): ProductSearchFeedProcessingResult;
}
