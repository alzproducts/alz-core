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
     * The date the hourly catch-up run starts from, null while unset (which switches the run off).
     *
     * @throws InvalidConfigurationException when the date is malformed
     */
    public function scheduleFrom(): ?DateTimeImmutable;

    /**
     * @throws InvalidConfigurationException when a label is set outside the sandbox environment
     */
    public function runLabels(): DocumentRunLabelsDTO;
}
