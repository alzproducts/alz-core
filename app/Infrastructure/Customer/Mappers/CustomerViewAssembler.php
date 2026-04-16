<?php

declare(strict_types=1);

namespace App\Infrastructure\Customer\Mappers;

use App\Domain\Customer\View\ValueObjects\CustomerView;
use App\Infrastructure\Customer\Models\CustomerViewModel;

/**
 * Assembles CustomerView VOs from CustomerViewModel rows.
 *
 * Passthrough conversion — no multi-column reconstruction or lookups needed.
 * Kept as a dedicated class (rather than inlining in a repository) so the
 * extension point matches Product / Order / Category / Brand conventions.
 */
final readonly class CustomerViewAssembler
{
    public function toViewDomain(CustomerViewModel $model): CustomerView
    {
        return new CustomerView(
            externalId: $model->external_id,
            email: $model->email,
            firstName: $model->first_name,
            lastName: $model->last_name,
            isTrade: $model->is_trade,
            isActive: $model->is_active,
            createdAt: $model->created_at->toDateTimeImmutable(),
        );
    }
}
