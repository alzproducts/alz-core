<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Infrastructure\QuickBooks\QuickBooksOrderSyncSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(QuickBooksOrderSyncSettings::class)]
final class QuickBooksOrderSyncSettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unsetFloors(): iterable
    {
        yield 'null' => [null];
        yield 'blank' => [''];
    }

    #[Test]
    #[DataProvider('unsetFloors')]
    public function an_unset_floor_is_null(?string $value): void
    {
        \config(['quickbooks.backfill_floor_date' => $value]);

        self::assertNull(self::settings()->backfillFloor());
    }

    #[Test]
    public function the_floor_is_the_configured_date(): void
    {
        \config(['quickbooks.backfill_floor_date' => '2026-07-01']);

        self::assertSame('2026-07-01 00:00:00', self::settings()->backfillFloor()?->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedFloors(): iterable
    {
        yield 'not a date' => ['soon'];
        yield 'another format' => ['01/07/2026'];
        yield 'a day that does not exist' => ['2026-02-30'];
        yield 'trailing time' => ['2026-07-01 10:00'];
    }

    #[Test]
    #[DataProvider('malformedFloors')]
    public function a_malformed_floor_throws(string $value): void
    {
        \config(['quickbooks.backfill_floor_date' => $value]);

        try {
            self::settings()->backfillFloor();
            self::fail('Expected a configuration failure');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('QUICKBOOKS_BACKFILL_FLOOR_DATE', $e->configKey);
        }
    }

    #[Test]
    public function sandbox_labels_are_read(): void
    {
        \config(['quickbooks.doc_number_prefix' => 'T1-', 'quickbooks.customer_name_suffix' => ' T1']);

        $labels = self::settings()->runLabels();

        self::assertSame('T1-', $labels->docNumberPrefix);
        self::assertSame(' T1', $labels->customerNameSuffix);
    }

    #[Test]
    public function blank_labels_are_valid_in_production(): void
    {
        \config(['quickbooks.doc_number_prefix' => '', 'quickbooks.customer_name_suffix' => null]);

        $labels = self::settings(QuickBooksEnvironment::Production)->runLabels();

        self::assertSame('', $labels->docNumberPrefix);
        self::assertSame('', $labels->customerNameSuffix);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function labels(): iterable
    {
        yield 'document number prefix' => ['doc_number_prefix', 'QBO_RUN_DOC_NUMBER_PREFIX'];
        yield 'customer name suffix' => ['customer_name_suffix', 'QBO_RUN_CUSTOMER_NAME_SUFFIX'];
    }

    #[Test]
    #[DataProvider('labels')]
    public function a_label_outside_the_sandbox_throws(string $key, string $envVar): void
    {
        \config(['quickbooks.doc_number_prefix' => '', 'quickbooks.customer_name_suffix' => '', "quickbooks.{$key}" => 'T1']);

        try {
            self::settings(QuickBooksEnvironment::Production)->runLabels();
            self::fail('Expected a configuration failure');
        } catch (InvalidConfigurationException $e) {
            self::assertSame($envVar, $e->configKey);
        }
    }

    private static function settings(QuickBooksEnvironment $environment = QuickBooksEnvironment::Sandbox): QuickBooksOrderSyncSettings
    {
        return new QuickBooksOrderSyncSettings(new QuickBooksConfig(
            environment: $environment,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            redirectUri: 'http://localhost/quickbooks/callback',
            realmId: '9130000000000001',
        ));
    }
}
