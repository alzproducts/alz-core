<?php

declare(strict_types=1);

namespace App\Application\Catalog\BestSellerLabels;

final readonly class BestSellerLabelTransformer
{
    public const string LABEL = 'Best Sellers';

    public const string FIELD = 'custom_label_4';

    /**
     * Append the Best Sellers label if not already present.
     *
     * @param list<string> $current
     * @return list<string>
     */
    public static function addLabel(array $current): array
    {
        if (\in_array(self::LABEL, $current, true)) {
            return $current;
        }

        return [...$current, self::LABEL];
    }

    /**
     * Remove the Best Sellers label; return null if the list becomes empty.
     *
     * @param list<string> $current
     * @return list<string>|null
     */
    public static function removeLabel(array $current): ?array
    {
        $result = \array_values(\array_filter(
            $current,
            static fn(string $v): bool => $v !== self::LABEL,
        ));

        return $result === [] ? null : $result;
    }
}
