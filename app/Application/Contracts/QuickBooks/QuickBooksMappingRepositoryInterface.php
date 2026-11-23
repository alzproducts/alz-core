<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingPickDTO;
use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface QuickBooksMappingRepositoryInterface
{
    /**
     * Database only, so a preflight can read it before any QBO call (ADR-0018).
     *
     * @return list<MappingKey> seeded keys with no ref for the realm
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function unresolvedKeys(string $realmId): array;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function resolvedRefs(string $realmId): ResolvedMappingRefsDTO;

    /**
     * @return list<MappingDTO> every seeded row, any realm
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function all(): array;

    /**
     * Writes unconditionally, so an equal pick still touches the row: callers go through RecordMappingPickUseCase.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RecordNotFoundException when the key has no row
     */
    public function record(MappingPickDTO $pick): void;
}
