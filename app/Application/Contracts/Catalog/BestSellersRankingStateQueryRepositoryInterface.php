<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface BestSellersRankingStateQueryRepositoryInterface
{
    /**
     * Top $limit sellers from the latest popularity snapshot, ordered by
     * final_score DESC. Excludes the non-seller floor (products pinned to
     * final_score = 1.00 in the snapshot).
     *
     * @return list<int> ShopWired product external IDs
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findTopRankedProductIds(int $limit): array;
}
