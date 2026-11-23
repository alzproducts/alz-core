<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Domain\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;

interface OrderSyncSettingsInterface
{
    /**
     * The configured date, null while unset.
     *
     * @throws InvalidConfigurationException when the date is malformed
     */
    public function backfillFloor(): ?DateTimeImmutable;

    /**
     * @throws InvalidConfigurationException when a label is set outside the sandbox environment
     */
    public function runLabels(): DocumentRunLabelsDTO;
}
