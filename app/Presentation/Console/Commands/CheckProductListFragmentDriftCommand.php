<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Catalog\Results\ProductListFragmentSampleDTO;
use App\Application\Catalog\UseCases\CheckProductListFragmentDriftUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\Responses\ProductListResponseFactory;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Rebuilds a sample of stored GET /api/products fragments from live inputs, reports and deletes
 * each one whose fingerprint still matches but whose body differs, then deletes orphaned fragments.
 */
final class CheckProductListFragmentDriftCommand extends Command
{
    private const int SAMPLE_SIZE = 50;

    protected $signature = 'catalog:check-list-fragment-drift';

    protected $description = 'Report and delete products list fragments that drifted from their live inputs, and orphaned fragments';

    public function handle(CheckProductListFragmentDriftUseCase $useCase, Request $request): int
    {
        try {
            $samples = $useCase->sample(self::SAMPLE_SIZE);
            $drifted = self::drifted($samples, $request);
            $useCase->recordDrift($drifted);
            $orphans = $useCase->deleteOrphans();
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException|InvalidCustomFieldValueException|InvalidEnumValueException|MissingRequiredDataException $e) {
            $this->error('Product list fragment drift check failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->report(\count($samples), $drifted, $orphans);

        return self::SUCCESS;
    }

    /**
     * @param list<ProductListFragmentDTO> $drifted
     */
    private function report(int $checked, array $drifted, int $orphans): void
    {
        foreach ($drifted as $fragment) {
            $this->warn("Drifted fragment deleted: product {$fragment->productId}, shape {$fragment->shape}");
        }

        $this->info(\sprintf('Checked %d fragments: %d drifted, %d orphans deleted', $checked, \count($drifted), $orphans));
    }

    /**
     * A matching fingerprint with a different body: some input reached the body but not the fingerprint.
     * A differing fingerprint is an ordinary stale fragment the next read replaces.
     *
     * @param list<ProductListFragmentSampleDTO> $samples
     *
     * @return list<ProductListFragmentDTO>
     */
    private static function drifted(array $samples, Request $request): array
    {
        $drifted = [];

        foreach ($samples as $sample) {
            if ($sample->rebuilt->fingerprint !== $sample->stored->fingerprint) {
                continue;
            }

            if (ProductListResponseFactory::render($sample->rebuilt, $request) !== $sample->stored->body) {
                $drifted[] = $sample->stored;
            }
        }

        return $drifted;
    }
}
