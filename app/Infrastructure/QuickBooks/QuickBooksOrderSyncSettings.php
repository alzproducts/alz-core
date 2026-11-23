<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Domain\Exceptions\InvalidConfigurationException;
use DateTimeImmutable;
use Override;
use ValueError;

final readonly class QuickBooksOrderSyncSettings implements OrderSyncSettingsInterface
{
    private const string FLOOR_FORMAT = 'Y-m-d';

    public function __construct(
        private QuickBooksConfig $config,
    ) {}

    /**
     * @throws InvalidConfigurationException when the date is malformed
     */
    #[Override]
    public function backfillFloor(): ?DateTimeImmutable
    {
        $value = self::text('quickbooks.backfill_floor_date', 'QUICKBOOKS_BACKFILL_FLOOR_DATE');
        if ($value === '') {
            return null;
        }

        try {
            $date = DateTimeImmutable::createFromFormat('!' . self::FLOOR_FORMAT, $value);
        } catch (ValueError $e) {
            throw new InvalidConfigurationException('QUICKBOOKS_BACKFILL_FLOOR_DATE', 'Expected YYYY-MM-DD', $e);
        }

        // intentional: createFromFormat rolls 2026-02-30 over to March instead of failing.
        if ($date === false || $date->format(self::FLOOR_FORMAT) !== $value) {
            throw new InvalidConfigurationException('QUICKBOOKS_BACKFILL_FLOOR_DATE', 'Expected YYYY-MM-DD');
        }

        return $date;
    }

    /**
     * @throws InvalidConfigurationException when a label is set outside the sandbox environment
     */
    #[Override]
    public function runLabels(): DocumentRunLabelsDTO
    {
        return new DocumentRunLabelsDTO(
            docNumberPrefix: $this->label('quickbooks.doc_number_prefix', 'QBO_RUN_DOC_NUMBER_PREFIX'),
            customerNameSuffix: $this->label('quickbooks.customer_name_suffix', 'QBO_RUN_CUSTOMER_NAME_SUFFIX'),
        );
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function label(string $configKey, string $envVar): string
    {
        $value = self::text($configKey, $envVar);
        if ($value !== '' && $this->config->environment !== QuickBooksEnvironment::Sandbox) {
            throw new InvalidConfigurationException($envVar, 'Run labels are sandbox only');
        }

        return $value;
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function text(string $configKey, string $envVar): string
    {
        $value = \config($configKey) ?? '';
        if (!\is_string($value)) {
            throw new InvalidConfigurationException($envVar, 'Expected text');
        }

        return $value;
    }
}
