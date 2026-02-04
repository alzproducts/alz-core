<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for reviews_io.product_ratings table.
 *
 * Stores Reviews.io product ratings cached locally for ShopWired sync.
 * The `sku` is the unique identifier for upsert operations.
 *
 * @property string $id Internal UUID
 * @property string $sku Product SKU
 * @property float|null $average_rating Average rating (NULL = no reviews)
 * @property int $num_ratings Number of ratings
 * @property CarbonImmutable $created_at When first synced
 * @property CarbonImmutable $updated_at When last updated
 */
final class ProductRatingModel extends Model
{
    use HasUuids;

    protected $table = 'reviews_io.product_ratings';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'average_rating' => 'float',
            'num_ratings' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
