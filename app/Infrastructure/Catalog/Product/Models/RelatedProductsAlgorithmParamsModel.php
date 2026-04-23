<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Models;

use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for catalog.related_products_algorithm_params.
 *
 * Read-only config table — versioned algorithm parameters for the
 * related products computation. At most one row may be active.
 *
 * @property int $id
 * @property int $algorithm_version
 * @property float $category_weight
 * @property float $title_weight
 * @property float $popularity_weight
 * @property int $max_results
 * @property float $min_content_score
 * @property float $default_popularity
 * @property bool $exclude_compare_list
 * @property bool $is_active
 * @property string|null $notes
 *
 * @implements EloquentDomainMappableInterface<RelatedProductsAlgorithmParams>
 */
final class RelatedProductsAlgorithmParamsModel extends Model implements EloquentDomainMappableInterface
{
    protected $table = 'catalog.related_products_algorithm_params';

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'algorithm_version' => 'integer',
            'category_weight' => 'float',
            'title_weight' => 'float',
            'popularity_weight' => 'float',
            'max_results' => 'integer',
            'min_content_score' => 'float',
            'default_popularity' => 'float',
            'exclude_compare_list' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    #[Override]
    public function toDomain(): RelatedProductsAlgorithmParams
    {
        return new RelatedProductsAlgorithmParams(
            categoryWeight: $this->category_weight,
            titleWeight: $this->title_weight,
            popularityWeight: $this->popularity_weight,
            maxResults: $this->max_results,
            minContentScore: $this->min_content_score,
            defaultPopularity: $this->default_popularity,
            excludeCompareList: $this->exclude_compare_list,
        );
    }

    /** @param RelatedProductsAlgorithmParams $entity */
    #[Override]
    public static function fromDomainAttributes(object $entity): array
    {
        return [
            'category_weight' => $entity->categoryWeight,
            'title_weight' => $entity->titleWeight,
            'popularity_weight' => $entity->popularityWeight,
            'max_results' => $entity->maxResults,
            'min_content_score' => $entity->minContentScore,
            'default_popularity' => $entity->defaultPopularity,
            'exclude_compare_list' => $entity->excludeCompareList,
        ];
    }
}
