<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimRulesDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncFailureDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use Closure;
use DateTimeImmutable;
use Override;

/**
 * The real repository, with a hook before each write: a hook that throws stops its write.
 */
final class InterceptedOrderSyncRepository implements OrderSyncRepositoryInterface
{
    /** @var (Closure(int): void)|null given the reference */
    public ?Closure $beforeClaim = null;

    /** @var (Closure(OrderSyncPostedDTO): void)|null */
    public ?Closure $beforeSynced = null;

    /** @var (Closure(OrderSyncFailureDTO): void)|null */
    public ?Closure $beforeFailed = null;

    /** @var (Closure(): void)|null */
    public ?Closure $beforeRelease = null;

    /** @var array<int, DateTimeImmutable> the lease expiry a claim reports, by reference */
    public array $leases = [];

    public function __construct(
        private readonly OrderSyncRepositoryInterface $inner,
    ) {}

    #[Override]
    public function claim(string $realmId, int $reference, int $orderExternalId, OrderSyncClaimRulesDTO $rules): ?OrderSyncClaimDTO
    {
        if ($this->beforeClaim !== null) {
            ($this->beforeClaim)($reference);
        }
        $claim = $this->inner->claim($realmId, $reference, $orderExternalId, $rules);
        $lease = $this->leases[$reference] ?? null;

        return $claim === null || $lease === null
            ? $claim
            : new OrderSyncClaimDTO($claim->orderSyncId, $claim->claimToken, $claim->attempts, $lease, $claim->storedCode);
    }

    #[Override]
    public function recordSynced(string $orderSyncId, string $claimToken, OrderSyncPostedDTO $posted): bool
    {
        if ($this->beforeSynced !== null) {
            ($this->beforeSynced)($posted);
        }

        return $this->inner->recordSynced($orderSyncId, $claimToken, $posted);
    }

    #[Override]
    public function recordFailed(string $orderSyncId, string $claimToken, OrderSyncFailureDTO $failure): bool
    {
        if ($this->beforeFailed !== null) {
            ($this->beforeFailed)($failure);
        }

        return $this->inner->recordFailed($orderSyncId, $claimToken, $failure);
    }

    #[Override]
    public function release(string $orderSyncId, string $claimToken): bool
    {
        if ($this->beforeRelease !== null) {
            ($this->beforeRelease)();
        }

        return $this->inner->release($orderSyncId, $claimToken);
    }
}
