<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingPickDTO;
use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\QuickBooksMappingModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Override;

final readonly class EloquentQuickBooksMappingRepository implements QuickBooksMappingRepositoryInterface
{
    // intentional: both operands are never null, so NOT (...) is the exact complement.
    private const string RESOLVED_SQL = 'qbo_ref_id IS NOT NULL AND realm_id IS NOT DISTINCT FROM ?';

    public function __construct(private EloquentGateway $eloquentGateway) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function unresolvedKeys(string $realmId): array
    {
        $rows = $this->rowsWhere('NOT (' . self::RESOLVED_SQL . ')', [$realmId]);

        return \array_map(static fn(QuickBooksMappingModel $row): MappingKey => $row->key, $rows);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function resolvedRefs(string $realmId): ResolvedMappingRefsDTO
    {
        $refIds = [];
        foreach ($this->rowsWhere(self::RESOLVED_SQL, [$realmId]) as $row) {
            if ($row->qbo_ref_id !== null) {
                $refIds[$row->key->value] = $row->qbo_ref_id;
            }
        }

        return new ResolvedMappingRefsDTO($refIds);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function all(): array
    {
        return \array_map(
            static fn(QuickBooksMappingModel $row): MappingDTO => new MappingDTO(
                key: $row->key,
                refId: $row->qbo_ref_id,
                refName: $row->qbo_ref_name,
                realmId: $row->realm_id,
                resolvedAt: $row->resolved_at,
            ),
            $this->rowsWhere('TRUE', []),
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RecordNotFoundException
     */
    #[Override]
    public function record(MappingPickDTO $pick): void
    {
        $affected = $this->eloquentGateway->updateWhere(QuickBooksMappingModel::class, 'key', $pick->key->value, [
            'qbo_ref_id' => $pick->refId,
            'qbo_ref_name' => $pick->refName,
            'realm_id' => $pick->realmId,
            'resolved_at' => new Expression('now()'),
            'updated_at' => new Expression('now()'),
        ]);

        if ($affected === 0) {
            throw new RecordNotFoundException('QuickBooks mapping', $pick->key->value);
        }
    }

    /**
     * @param literal-string $condition
     * @param list<string>   $bindings
     *
     * @return list<QuickBooksMappingModel>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function rowsWhere(string $condition, array $bindings): array
    {
        /** @var Collection<int, QuickBooksMappingModel> $rows */
        $rows = $this->eloquentGateway->query(
            static fn(): Collection => QuickBooksMappingModel::query()
                ->fromQuery("SELECT * FROM quickbooks.mappings WHERE {$condition} ORDER BY id", $bindings),
        );

        return \array_values($rows->all());
    }
}
