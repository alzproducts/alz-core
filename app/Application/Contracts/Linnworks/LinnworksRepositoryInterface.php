<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryInterface;

/**
 * Base repository interface for Linnworks entity persistence.
 *
 * Extends the common RepositoryInterface with Linnworks-specific context.
 * Linnworks remains the source of truth; local storage provides fast queries
 * and offline resilience.
 *
 * Entity-specific repositories should extend this interface and add
 * custom query methods as needed.
 *
 * Key difference from ShopWired: Linnworks uses string GUIDs as identifiers,
 * not integer external IDs.
 *
 * @template T of object
 *
 * @extends RepositoryInterface<T>
 */
interface LinnworksRepositoryInterface extends RepositoryInterface {}
