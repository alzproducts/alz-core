<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Database\Factories\ProductSlice\CustomFieldDefinitionRowFactory;
use Database\Seeders\ProductSlice\SeedIds;
use Database\Seeders\ProductSlice\SeedProfile;
use UnexpectedValueException;

/**
 * One definition per profile `item_type/type` slot; the custom fields the slice's rows carry get named slots
 * of the type their values need, so the registry types them.
 */
final readonly class CustomFieldDefinitionBuilder
{
    public const array FREE_DELIVERY_VALUES = ['Standard', 'Express'];

    /** name => item_type/type */
    public const array TYPED_FIELDS = [
        'free_delivery' => 'product/list',
        'average_rating' => 'product/text',
        'num_ratings' => 'product/text',
        'best_buy' => 'product/toggle',
        'dimensions' => 'product/value_list',
        'is_main_category' => 'category/toggle',
        'custom_h1' => 'category/text',
    ];

    private const array CHOICE_VALUES = ['Option A', 'Option B', 'Option C'];

    /**
     * @return list<array<string, mixed>>
     */
    public function build(SeedProfile $profile): array
    {
        $factory = CustomFieldDefinitionRowFactory::new();
        $rows = [];

        foreach ($this->slots($profile) as $index => [$itemType, $type, $name]) {
            $rows[] = $factory->row([
                'external_id' => SeedIds::CUSTOM_FIELD_BASE + $index,
                'name' => $name,
                'type' => $type,
                'item_type' => $itemType,
                'sort_order' => $index,
                'allowed_values' => self::allowedValues($name, $type),
            ]);
        }

        return $rows;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function slots(SeedProfile $profile): array
    {
        $histogram = $profile->histogram('custom_field_definitions');
        $named = self::namedSlots($histogram);
        $slots = [];

        foreach ($histogram as $slot => $count) {
            [$itemType, $type] = self::splitSlot((string) $slot);

            for ($n = 0; $n < $count; ++$n) {
                $slots[] = [$itemType, $type, $named[$slot][$n] ?? \sprintf('%s_%s_%02d', $itemType, $type, $n + 1)];
            }
        }

        return $slots;
    }

    /**
     * @param array<array-key, int> $histogram
     *
     * @return array<string, list<string>>
     */
    private static function namedSlots(array $histogram): array
    {
        $named = [];

        foreach (self::TYPED_FIELDS as $name => $slot) {
            $named[$slot][] = $name;

            if (\count($named[$slot]) > ($histogram[$slot] ?? 0)) {
                throw new UnexpectedValueException('Seed profile has too few custom field definitions of type ' . $slot);
            }
        }

        return $named;
    }

    /**
     * @return array{string, string}
     */
    private static function splitSlot(string $slot): array
    {
        $parts = \explode('/', $slot, 2);

        if (\count($parts) !== 2) {
            throw new UnexpectedValueException('Custom field definition slot is not item_type/type: ' . $slot);
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @return list<string>|null
     */
    private static function allowedValues(string $name, string $type): ?array
    {
        if ($name === 'free_delivery') {
            return self::FREE_DELIVERY_VALUES;
        }

        return $type === 'choice' || $type === 'list' ? self::CHOICE_VALUES : null;
    }
}
