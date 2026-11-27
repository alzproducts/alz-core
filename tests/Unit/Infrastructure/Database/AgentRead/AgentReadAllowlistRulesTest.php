<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database\AgentRead;

use App\Infrastructure\Database\AgentRead\AgentReadAllowlist;
use App\Infrastructure\Database\AgentRead\AgentReadAllowlistRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentReadAllowlistRules::class)]
#[CoversClass(AgentReadAllowlist::class)]
final class AgentReadAllowlistRulesTest extends TestCase
{
    private const array TYPES = [
        'shopwired.products.id' => 'int8',
        'shopwired.products.title' => 'text',
        'shopwired.products.custom_fields' => 'jsonb',
        'shopwired.products.raw_feed' => 'json',
    ];

    #[Test]
    public function a_clean_allowlist_has_no_violations(): void
    {
        $allowlist = new AgentReadAllowlist(
            ['shopwired.products' => ['id', 'title', 'custom_fields']],
            ['shopwired.products.custom_fields'],
        );

        self::assertSame([], (new AgentReadAllowlistRules())->violations($allowlist, self::TYPES));
    }

    #[Test]
    public function a_relation_outside_the_nine_schemas_is_named(): void
    {
        $allowlist = new AgentReadAllowlist(['access.staff_users' => ['id']], []);

        self::assertSame(
            ['access.staff_users: relation is outside the allowlisted schemas'],
            (new AgentReadAllowlistRules())->violations($allowlist, ['access.staff_users.id' => 'int8']),
        );
    }

    #[Test]
    #[DataProvider('excludedColumnNames')]
    public function an_excluded_column_name_is_named(string $column): void
    {
        $allowlist = new AgentReadAllowlist(['shopwired.orders' => ['id', $column]], []);
        $types = ['shopwired.orders.id' => 'int8', "shopwired.orders.{$column}" => 'text'];

        $violations = (new AgentReadAllowlistRules())->violations($allowlist, $types);

        self::assertCount(1, $violations);
        self::assertStringStartsWith("shopwired.orders.{$column}: column name matches the excluded ", $violations[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function excludedColumnNames(): iterable
    {
        foreach (['billing_email', 'phone', 'delivery_postcode', 'ip_address', 'gift_note', 'date_of_birth', 'updated_by', 'refresh_token'] as $column) {
            yield $column => [$column];
        }
    }

    #[Test]
    public function a_json_column_off_the_exception_list_is_named(): void
    {
        $allowlist = new AgentReadAllowlist(['shopwired.products' => ['id', 'raw_feed']], []);

        self::assertSame(
            ['shopwired.products.raw_feed: json column is not on the JSON exception list'],
            (new AgentReadAllowlistRules())->violations($allowlist, self::TYPES),
        );
    }

    #[Test]
    #[DataProvider('forbiddenJsonRelations')]
    public function a_json_exception_on_a_personal_data_relation_is_named(string $exception, string $substring): void
    {
        $allowlist = new AgentReadAllowlist([], [$exception]);

        self::assertSame(
            ["{$exception}: JSON exception on a relation whose name contains '{$substring}'"],
            (new AgentReadAllowlistRules())->violations($allowlist, []),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenJsonRelations(): iterable
    {
        yield 'order' => ['shopwired.order_products.variation', 'order'];
        yield 'customer' => ['shopwired.customers.custom_fields', 'customer'];
        yield 'basket' => ['checkout.basket_snapshots.vat_relief', 'basket'];
        yield 'contact' => ['customer_service.contact_submission_actions.payload', 'contact'];
        yield 'call' => ['customer_service.call_tracking_visits.raw', 'call'];
    }

    #[Test]
    public function a_column_that_does_not_exist_is_named(): void
    {
        $allowlist = new AgentReadAllowlist(['shopwired.products' => ['id', 'gone']], []);

        self::assertSame(
            ['shopwired.products.gone: column does not exist'],
            (new AgentReadAllowlistRules())->violations($allowlist, self::TYPES),
        );
    }
}
