<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Application\Linnworks\DTOs\ModifiedStockItemDTO;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for ModifiedStockItemQuery results.
 *
 * @internal Implementation detail of ModifiedStockItemQuery
 */
final class ModifiedStockItemRow extends Data
{
    public function __construct(
        #[MapInputName('pkStockItemID')]
        public readonly string $stockItemId,
        #[MapInputName('ModifiedDate')]
        public readonly string $modifiedDate,
    ) {}

    public function toDomain(): ModifiedStockItemDTO
    {
        return new ModifiedStockItemDTO(
            stockItemId: Guid::fromTrusted($this->stockItemId),
            modifiedDate: CarbonImmutable::parse($this->modifiedDate),
        );
    }
}

/**
 * Query stock items modified since a given datetime.
 *
 * Returns up to LIMIT rows ordered by ModifiedDate ASC, enabling
 * cursor-based incremental sync. If LIMIT rows are returned, the
 * caller should treat this as an overflow condition.
 *
 * @extends AbstractLinnworksQuery<list<ModifiedStockItemDTO>>
 *
 * @template-pattern Query Object
 */
final readonly class ModifiedStockItemQuery extends AbstractLinnworksQuery
{
    /**
     * Maximum rows returned per query — used as overflow threshold.
     */
    public const int LIMIT = 500;

    public function __construct(
        private DateTimeImmutable $since,
    ) {}

    protected function buildQueryBody(): string
    {
        $escapedDate = SqlQueryBuilder::escapeString($this->since->format('Y-m-d H:i:s.v'));

        return <<<SQL
            SELECT TOP 500 pkStockItemID, CAST(ModifiedDate AS DATETIME2(0)) AS 'ModifiedDate'
            FROM [StockItem]
            WHERE CAST(ModifiedDate AS DATETIME2(0)) > {$escapedDate}
            ORDER BY ModifiedDate ASC
            SQL;
    }

    /**
     * @return list<ModifiedStockItemDTO>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): ModifiedStockItemDTO => ModifiedStockItemRow::from($row)->toDomain(),
            $response->results,
        );
    }
}
