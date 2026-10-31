<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Linnworks\Models\SupplierModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<SupplierModel>
 */
final class SupplierRowFactory extends AbstractSliceRowFactory
{
    protected $model = SupplierModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'pk_supplier_id' => $this->faker->uuid(),
            'supplier_name' => $this->faker->company(),
            'contact_name' => $this->faker->name(),
            'address' => $this->faker->streetAddress(),
            'alternative_address' => null,
            'city' => $this->faker->city(),
            'region' => null,
            'country' => 'United Kingdom',
            'post_code' => $this->faker->postcode(),
            'telephone_number' => $this->faker->numerify('01632 ######'),
            'secondary_tel_number' => null,
            'fax_number' => null,
            'email' => $this->faker->safeEmail(),
            'web_page' => null,
            'currency' => 'GBP',
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
        ];
    }
}
