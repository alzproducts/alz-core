<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;

/**
 * Repository for Linnworks order persistence.
 *
 * Sync strategy: upsert by linnworks_order_id (Linnworks GUID).
 * Each save() persists the order with its child entities (items,
 * extended properties, notes) atomically within a transaction.
 *
 * @extends RepositoryWriteInterface<LinnworksOrder>
 */
interface LinnworksOrderRepositoryInterface extends RepositoryWriteInterface {}
