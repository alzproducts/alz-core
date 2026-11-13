<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Infrastructure\Shopwired\Repositories\EloquentProductStockRepository;
use RuntimeException;

/**
 * The stock mirror over every SKU, alternating each SKU's quantity between its original value and one more,
 * so every call rewrites real values and the original state can be put back.
 */
final class StockMirrorWriter
{
    /** @var list<ItemStockLevel> */
    private array $originalLevels = [];

    private bool $bumped = false;

    public function __construct(
        private readonly EloquentProductStockRepository $stock,
    ) {}

    /**
     * @throws RuntimeException
     */
    public function captureBaseline(): void
    {
        $this->originalLevels = $this->stock->getAllStockLevels();
        $this->bumped = false;
    }

    public function skuCount(): int
    {
        return \count($this->originalLevels);
    }

    /**
     * @throws RuntimeException
     */
    public function mirror(): void
    {
        $this->bumped = ! $this->bumped;

        $this->stock->updateStockLevels($this->bumped ? $this->bumpedLevels() : $this->originalLevels);
    }

    /**
     * @throws RuntimeException
     */
    public function restore(): void
    {
        $this->stock->updateStockLevels($this->originalLevels);
        $this->bumped = false;
    }

    /**
     * @return list<ItemStockLevel>
     */
    private function bumpedLevels(): array
    {
        return \array_map(
            static fn(ItemStockLevel $level): ItemStockLevel => new ItemStockLevel($level->sku, $level->quantity + 1),
            $this->originalLevels,
        );
    }
}
