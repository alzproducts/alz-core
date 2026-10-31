<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Catalog\Results\ProductListFragmentSampleDTO;
use App\Application\Contracts\Catalog\ProductListFragmentStoreInterface;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Application\Contracts\ErrorReporterInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\Exceptions\ProductListFragmentDriftException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Safety net for the fingerprinted products list: rebuilds a sample of stored fragments from live
 * inputs so the caller can compare bodies, drops the fragments it reports as drifted, and sweeps
 * fragments whose product no longer exists.
 */
final readonly class CheckProductListFragmentDriftUseCase
{
    private const string NO_INCLUDES_SHAPE = 'none';

    public function __construct(
        private ProductListFragmentStoreInterface $store,
        private ProductListReaderInterface $reader,
        private ErrorReporterInterface $errorReporter,
        private LoggerInterface $logger,
    ) {}

    /**
     * A fragment whose product no longer exists has no rebuilt entry and is left out; the orphan sweep deletes it.
     *
     * @return list<ProductListFragmentSampleDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws InvalidEnumValueException When a stored shape names an unknown include
     * @throws MissingRequiredDataException
     */
    public function sample(int $limit): array
    {
        $this->logger->info('Sampling product list fragments for drift', ['limit' => $limit]);

        $fragments = $this->store->sample($limit);
        $shapes = \array_unique(\array_map(static fn(ProductListFragmentDTO $fragment): string => $fragment->shape, $fragments));
        $samples = [];

        foreach ($shapes as $shape) {
            $group = \array_values(\array_filter($fragments, static fn(ProductListFragmentDTO $fragment): bool => $fragment->shape === $shape));
            \array_push($samples, ...$this->pairWithRebuilds($shape, $group));
        }

        $this->logger->info('Sampled product list fragments', ['sampled' => \count($fragments), 'rebuilt' => \count($samples)]);

        return $samples;
    }

    /**
     * @param list<ProductListFragmentDTO> $drifted
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function recordDrift(array $drifted): void
    {
        $this->logger->info('Recording product list fragment drift', ['drifted' => \count($drifted)]);

        if ($drifted === []) {
            return;
        }

        foreach ($drifted as $fragment) {
            $exception = new ProductListFragmentDriftException($fragment->productId, $fragment->shape);
            $this->errorReporter->report($exception, $exception->context());
        }

        $this->store->deleteMany(\array_map(
            static fn(ProductListFragmentDTO $fragment): array => ['productId' => $fragment->productId, 'shape' => $fragment->shape],
            $drifted,
        ));

        $this->logger->info('Deleted drifted product list fragments');
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteOrphans(): int
    {
        $this->logger->info('Deleting orphaned product list fragments');

        $deleted = $this->store->deleteOrphans();

        $this->logger->info('Deleted orphaned product list fragments', ['deleted' => $deleted]);

        return $deleted;
    }

    /**
     * @param list<ProductListFragmentDTO> $fragments all of one shape
     *
     * @return list<ProductListFragmentSampleDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws InvalidEnumValueException
     * @throws MissingRequiredDataException
     */
    private function pairWithRebuilds(string $shape, array $fragments): array
    {
        $rebuilt = self::byProductId($this->reader->rebuild(
            \array_map(static fn(ProductListFragmentDTO $fragment): string => $fragment->productId, $fragments),
            self::includes($shape),
        ));
        $pairs = [];

        foreach ($fragments as $fragment) {
            if (isset($rebuilt[$fragment->productId])) {
                $pairs[] = new ProductListFragmentSampleDTO($fragment, $rebuilt[$fragment->productId]);
            }
        }

        return $pairs;
    }

    /**
     * Inverse of the stored shape encoding: sorted include values joined by ',', or 'none'.
     *
     * @return list<ProductInclude>
     *
     * @throws InvalidEnumValueException
     */
    private static function includes(string $shape): array
    {
        if ($shape === self::NO_INCLUDES_SHAPE) {
            return [];
        }

        return \array_map(ProductInclude::fromValue(...), \explode(',', $shape));
    }

    /**
     * @param list<ProductListEntryDTO> $entries
     *
     * @return array<string, ProductListEntryDTO>
     */
    private static function byProductId(array $entries): array
    {
        $byId = [];

        foreach ($entries as $entry) {
            $byId[$entry->productId] = $entry;
        }

        return $byId;
    }
}
