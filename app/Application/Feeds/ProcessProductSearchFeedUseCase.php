<?php

declare(strict_types=1);

namespace App\Application\Feeds;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedFeedDataException;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate product search feed processing.
 *
 * Coordinates the workflow: fetch source feed, transform XML (substitute
 * title elements with display titles), and upload to cloud storage for
 * site search consumption.
 */
final readonly class ProcessProductSearchFeedUseCase
{
    public function __construct(
        private ProductSearchFeedProcessorInterface $processor,
        private string $sourceUrl,
        private string $storagePath,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute the feed processing workflow.
     *
     * @throws ExternalServiceUnavailableException When source feed is unreachable
     * @throws MalformedFeedDataException When source feed XML is malformed
     * @throws StorageOperationFailedException When upload to storage fails
     */
    public function execute(): void
    {
        $this->logger->info('Starting product search feed processing', [
            'source_url' => $this->sourceUrl,
            'output_path' => $this->storagePath,
        ]);

        $result = $this->processor->process(
            sourceUrl: $this->sourceUrl,
            outputPath: $this->storagePath,
        );

        $this->logger->info('Product search feed processing completed', [
            'items_processed' => $result->itemsProcessed,
            'titles_substituted' => $result->titlesSubstituted,
            'duration_seconds' => $result->durationSeconds,
        ]);
    }
}
