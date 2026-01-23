<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryInterface;

/**
 * Base repository interface for ShopWired entity persistence.
 *
 * Extends the common RepositoryInterface with ShopWired-specific context.
 * ShopWired remains the source of truth; local storage provides fast queries
 * and offline resilience.
 *
 * For external ID lookups, use getByColumn($id, 'external_id').
 *
 * @template T of object
 *
 * @extends RepositoryInterface<T>
 */
interface ShopwiredRepositoryInterface extends RepositoryInterface {}
