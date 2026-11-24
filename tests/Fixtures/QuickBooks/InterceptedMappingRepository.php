<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingPickDTO;
use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use Override;
use Throwable;

/**
 * The real repository, except that reading the resolved refs throws.
 */
final readonly class InterceptedMappingRepository implements QuickBooksMappingRepositoryInterface
{
    public function __construct(
        private QuickBooksMappingRepositoryInterface $inner,
        private Throwable $refsThrow,
    ) {}

    #[Override]
    public function unresolvedKeys(string $realmId): array
    {
        return $this->inner->unresolvedKeys($realmId);
    }

    #[Override]
    public function resolvedRefs(string $realmId): ResolvedMappingRefsDTO
    {
        throw $this->refsThrow;
    }

    #[Override]
    public function all(): array
    {
        return $this->inner->all();
    }

    #[Override]
    public function record(MappingPickDTO $pick): void
    {
        $this->inner->record($pick);
    }
}
