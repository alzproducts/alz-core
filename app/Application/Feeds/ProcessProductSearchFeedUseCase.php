<?php

declare(strict_types=1);

namespace App\Application\Feeds;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Exceptions\MalformedFeedDataException;
use App\Domain\Exceptions\StorageOperationFailedException;
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
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute the feed processing workflow.
     *
     * Validates configuration, then delegates to the processor for
     * fetch, transform, and upload operations.
     *
     * @throws InvalidConfigurationException When required config is missing
     * @throws ExternalServiceUnavailableException When source feed is unreachable
     * @throws MalformedFeedDataException When source feed XML is malformed
     * @throws StorageOperationFailedException When upload to storage fails
     */
    public function execute(): void
    {
        $config = self::validateConfig();

        $this->logger->info('Starting product search feed processing', [
            'source_url' => $config['source_url'],
            'output_path' => $config['storage_path'],
        ]);

        $result = $this->processor->process(
            sourceUrl: $config['source_url'],
            outputPath: $config['storage_path'],
        );

        $this->logger->info('Product search feed processing completed', [
            'items_processed' => $result->itemsProcessed,
            'titles_substituted' => $result->titlesSubstituted,
            'duration_seconds' => $result->durationSeconds,
        ]);
    }

    /**
     * Validate that required feed configuration exists.
     *
     * @return array{source_url: string, storage_path: string, storage_disk: string}
     *
     * @throws InvalidConfigurationException When required config is missing or invalid
     */
    private static function validateConfig(): array
    {
        /** @var mixed $config */
        $config = \config('feeds.doofinder');

        if (!\is_array($config)) {
            throw new InvalidConfigurationException(
                'feeds.doofinder',
                'Product search feed configuration is missing',
            );
        }

        $required = ['source_url', 'storage_path', 'storage_disk'];

        foreach ($required as $key) {
            if (!isset($config[$key]) || !\is_string($config[$key]) || ($config[$key] === '')) {
                throw new InvalidConfigurationException(
                    "feeds.doofinder.{$key}",
                    "Product search feed config missing required key: {$key}",
                );
            }
        }

        /** @var array{source_url: string, storage_path: string, storage_disk: string} */
        return [
            'source_url' => $config['source_url'],
            'storage_path' => $config['storage_path'],
            'storage_disk' => $config['storage_disk'],
        ];
    }
}
