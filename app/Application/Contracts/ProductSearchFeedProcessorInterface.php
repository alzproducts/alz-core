<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Feeds\ProductSearchFeedProcessingResult;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\MalformedFeedDataException;

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
     * (e.g., substituting element values), and uploads the result to the
     * specified storage disk.
     *
     * @param string $sourceUrl  URL of the source feed to fetch
     * @param string $outputPath Path within the storage disk for the output file
     * @param string $disk       Storage disk name (e.g., 's3', 'local')
     *
     * @return ProductSearchFeedProcessingResult Statistics about the processing operation
     *
     * @throws ExternalServiceUnavailableException When source feed unreachable or storage fails
     * @throws MalformedFeedDataException When source feed XML is malformed or unparseable
     */
    public function process(string $sourceUrl, string $outputPath, string $disk): ProductSearchFeedProcessingResult;
}
