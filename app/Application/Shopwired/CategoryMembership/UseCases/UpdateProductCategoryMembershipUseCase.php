<?php

declare(strict_types=1);

namespace App\Application\Shopwired\CategoryMembership\UseCases;

use App\Application\Contracts\Shopwired\ProductFieldUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Adds and/or removes ShopWired categories on a product in a single PUT.
 *
 * Idempotency lives here — the use case re-fetches the live product and
 * diffs against its current category_ids, so callers can safely pass
 * already-applied adds/removes without triggering spurious API writes.
 */
final readonly class UpdateProductCategoryMembershipUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepo,
        private ProductFieldUpdateClientInterface $fieldUpdateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  list<IntId>  $addCategoryIds     Categories to add (ignored if product is already a member)
     * @param  list<IntId>  $removeCategoryIds  Categories to remove (ignored if product is not a member)
     *
     * @throws ResourceNotFoundException When product not found in DB
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws ResourceNotAvailableException When product not found on API
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws DatabaseOperationFailedException On DB query failure
     * @throws DuplicateRecordException On DB constraint violation
     */
    public function execute(IntId $productId, array $addCategoryIds, array $removeCategoryIds): void
    {
        $view = $this->productRepo->findDetailedProductView($productId);
        $currentInts = \array_map(static fn(IntId $id): int => $id->value, $view->categoryIds);
        $addInts = \array_map(static fn(IntId $id): int => $id->value, $addCategoryIds);
        $removeInts = \array_map(static fn(IntId $id): int => $id->value, $removeCategoryIds);

        $effectiveAdds = \array_values(\array_diff($addInts, $currentInts));
        $effectiveRemoves = \array_values(\array_intersect($removeInts, $currentInts));

        if ($effectiveAdds === [] && $effectiveRemoves === []) {
            $this->logNoOp($productId, $addInts, $removeInts);

            return;
        }

        $this->applyUpdate($productId, $currentInts, $effectiveAdds, $effectiveRemoves);
    }

    /**
     * @param  list<int>  $requestedAdds
     * @param  list<int>  $requestedRemoves
     */
    private function logNoOp(IntId $productId, array $requestedAdds, array $requestedRemoves): void
    {
        $this->logger->info('UpdateProductCategoryMembership: no-op — live state already matches', [
            'product_id' => $productId->value,
            'requested_adds' => $requestedAdds,
            'requested_removes' => $requestedRemoves,
        ]);
    }

    /**
     * @param  list<int>  $currentCategoryIds
     * @param  list<int>  $effectiveAdds
     * @param  list<int>  $effectiveRemoves
     *
     * @throws InvalidCustomFieldValueException
     * @throws ResourceNotAvailableException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     */
    private function applyUpdate(
        IntId $productId,
        array $currentCategoryIds,
        array $effectiveAdds,
        array $effectiveRemoves,
    ): void {
        $newCategoryIds = self::buildNewCategoryIds($currentCategoryIds, $effectiveAdds, $effectiveRemoves);
        $this->fieldUpdateClient->update($productId->value, ProductFieldUpdate::categories($newCategoryIds));

        $this->logger->info('UpdateProductCategoryMembership: updated', [
            'product_id' => $productId->value,
            'added' => $effectiveAdds,
            'removed' => $effectiveRemoves,
        ]);
    }

    /**
     * Assumes $adds are not already in $current and $removes are actually in
     * $current — both guaranteed by the execute() diff step.
     *
     * @param  list<int>  $current
     * @param  list<int>  $adds
     * @param  list<int>  $removes
     * @return list<int>
     */
    private static function buildNewCategoryIds(array $current, array $adds, array $removes): array
    {
        $filtered = \array_filter(
            $current,
            static fn(int $id): bool => ! \in_array($id, $removes, true),
        );

        return \array_values(\array_unique([...$filtered, ...$adds]));
    }
}
