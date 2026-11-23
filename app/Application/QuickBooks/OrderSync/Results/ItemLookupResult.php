<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * QBO item ids by SKU, matched ignoring case as QBO compares names, plus each SKU no item carries.
 */
final readonly class ItemLookupResult
{
    /**
     * @param array<array-key, non-empty-string> $idsByFoldedName keyed by folded name; intentional: an all-digit name
     *                                                             becomes an int key, so the keys are only ever looked up
     * @param list<Sku>                          $missing
     */
    private function __construct(
        private array $idsByFoldedName,
        public array $missing,
    ) {}

    /**
     * @param list<Sku>                                              $requested
     * @param list<array{name: string, id: non-empty-string}>        $found     QBO rows; the first per folded name wins
     */
    public static function fromMatches(array $requested, array $found): self
    {
        $ids = [];
        foreach ($found as $item) {
            $ids[self::fold($item['name'])] ??= $item['id'];
        }

        $missing = [];
        foreach ($requested as $sku) {
            $folded = self::fold($sku->value);
            if (!isset($ids[$folded])) {
                $missing[$folded] ??= $sku;
            }
        }

        return new self($ids, \array_values($missing));
    }

    /**
     * @return non-empty-string|null
     */
    public function itemIdFor(Sku $sku): ?string
    {
        return $this->idsByFoldedName[self::fold($sku->value)] ?? null;
    }

    private static function fold(string $name): string
    {
        return \mb_strtolower($name);
    }
}
