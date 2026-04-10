<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Dispatchers;

use App\Application\Contracts\Catalog\CatalogSyncDispatcherInterface;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Catalog\UpdateProductFilterJob;
use BackedEnum;
use Override;

final readonly class QueuedCatalogSyncDispatcher implements CatalogSyncDispatcherInterface
{
    #[Override]
    public function dispatchFilterUpdate(IntId $productId, int $optionNo, ?array $values): void
    {
        UpdateProductFilterJob::dispatch(
            $productId,
            $optionNo,
            $values !== null
                ? \array_map(static fn(BackedEnum $v): string => (string) $v->value, $values)
                : null,
        );
    }
}
