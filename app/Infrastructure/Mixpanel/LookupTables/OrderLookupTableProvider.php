<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\LookupTables;

use App\Application\Contracts\LookupTableProviderInterface;
use App\Domain\Catalog\Order\ValueObjects\OrderAnalyticsHash;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Mixpanel\MixpanelConfig;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Provides order enrichment lookup table data from ShopWired.
 *
 * Fetches order and customer data, computing enrichment fields (LTV, first order,
 * trade status) using PostgreSQL window functions for single-pass efficiency.
 *
 * Handles the frontend hash bug period (Sept 2025 - Jan 2026) by generating
 * duplicate rows with both standard and fallback salt hashes.
 */
final readonly class OrderLookupTableProvider implements LookupTableProviderInterface
{
    /**
     * Bug period where frontend JavaScript sometimes used a fallback salt for order hashing.
     *
     * Between Sept 2025 and Jan 2026, the frontend tracking script could use either:
     * - Standard salt (from config): SHA-256(reference + ANALYTICS_SALT)
     * - Fallback salt (timestamp-based): SHA-256(reference + 'alz-' + timestamp)
     *
     * Orders placed during this period need duplicate Mixpanel lookup table rows
     * with both hash variants to ensure discoverability regardless of which hash
     * was used when the order was tracked.
     *
     * Note: BUG_PERIOD_END is the day AFTER the last affected day (exclusive upper bound).
     *
     * @see https://github.com/alzproducts/alz-core/issues/134 Original bug investigation
     */
    private const string BUG_PERIOD_START = '2025-09-01';

    private const string BUG_PERIOD_END = '2026-01-27';

    /**
     * Prefix used by frontend fallback hashing algorithm during bug period.
     * Pattern: 'alz-' + Unix timestamp of order placement.
     */
    private const string FALLBACK_SALT_PREFIX = 'alz-';

    public function __construct(
        private MixpanelConfig $config,
        private DatabaseGateway $database,
    ) {}

    public function getTableKey(): string
    {
        return 'order_enrichment';
    }

    public function getSourceName(): string
    {
        return 'ShopWired Orders';
    }

    /**
     * @return list<string>
     */
    public function getHeaders(): array
    {
        return [
            'order_id_hashed',
            'user_is_credit',
            'user_account_created_at',
            'user_company_name',
            'user_is_trade',
            'order_is_first_order',
            'user_total_orders',
            'user_lifetime_value',
        ];
    }

    /**
     * Fetch all orders with enrichment data.
     *
     * Uses PostgreSQL window functions for efficient single-pass calculation
     * of first_order, total_orders, and lifetime_value per customer.
     *
     * @return list<list<string>>
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws DatabaseOperationFailedException When query fails permanently
     * @throws DuplicateRecordException When unique constraint violated (defensive - shouldn't occur in reads)
     * @throws DateMalformedStringException When order_placed_at contains invalid date
     */
    public function fetchRows(): array
    {
        /** @var list<object{reference: string, order_placed_at: string, user_is_credit: bool, user_account_created_at: string|null, user_company_name: string|null, user_is_trade: bool, order_is_first_order: bool, user_total_orders: string, user_lifetime_value: string}> $results */
        $results = $this->database->query(
            fn(): array => $this->database->connection()->select($this->buildQuery()),
        );

        $rows = [];
        $bugPeriodStart = new DateTimeImmutable(self::BUG_PERIOD_START);
        $bugPeriodEnd = new DateTimeImmutable(self::BUG_PERIOD_END);

        foreach ($results as $row) {
            $orderPlacedAt = new DateTimeImmutable($row->order_placed_at);

            // Standard hash row (always included)
            $standardHash = OrderAnalyticsHash::fromReference(
                (int) $row->reference,
                $this->config->analyticsSalt,
            )->value;

            $rows[] = $this->buildRow($standardHash, $row);

            // Bug period: add duplicate row with fallback salt hash
            if ($orderPlacedAt >= $bugPeriodStart && $orderPlacedAt < $bugPeriodEnd) {
                $fallbackSalt = self::FALLBACK_SALT_PREFIX . $orderPlacedAt->getTimestamp();
                $fallbackHash = \hash('sha256', $row->reference . $fallbackSalt);
                $rows[] = $this->buildRow($fallbackHash, $row);
            }
        }

        return $rows;
    }

    /**
     * Build a row array from query result.
     *
     * All values must be strings for Mixpanel lookup tables.
     *
     * @param object{user_is_credit: bool, user_account_created_at: string|null, user_company_name: string|null, user_is_trade: bool, order_is_first_order: bool, user_total_orders: string, user_lifetime_value: string} $row
     *
     * @return list<string>
     */
    private function buildRow(string $hash, object $row): array
    {
        return [
            $hash,
            $row->user_is_credit ? 'true' : 'false',
            $row->user_account_created_at ?? '',
            $row->user_company_name ?? '',
            $row->user_is_trade ? 'true' : 'false',
            $row->order_is_first_order ? 'true' : 'false',
            $row->user_total_orders,
            \number_format((float) $row->user_lifetime_value, 2, '.', ''),
        ];
    }

    /**
     * Build SQL query with window functions for efficient computation.
     *
     * Window functions calculate first_order, total_orders, and lifetime_value
     * in a single pass without subqueries or self-joins.
     */
    private function buildQuery(): string
    {
        return <<<'SQL'
            SELECT
                o.reference,
                o.order_placed_at,
                c.is_credit_enabled AS user_is_credit,
                c.shopwired_created_at AS user_account_created_at,
                c.company_name AS user_company_name,
                c.is_trade AS user_is_trade,
                -- First order: ROW_NUMBER = 1 among non-cancelled/refunded orders for this customer
                CASE
                    WHEN o.lifecycle_status NOT IN ('cancelled', 'refunded')
                         AND ROW_NUMBER() OVER (
                             PARTITION BY o.customer_id
                             ORDER BY CASE WHEN o.lifecycle_status IN ('cancelled', 'refunded') THEN 1 ELSE 0 END,
                                      o.order_placed_at
                         ) = 1
                    THEN true
                    ELSE false
                END AS order_is_first_order,
                -- Total orders (excluding cancelled/refunded) for this customer
                COUNT(*) FILTER (WHERE o.lifecycle_status NOT IN ('cancelled', 'refunded'))
                    OVER (PARTITION BY o.customer_id) AS user_total_orders,
                -- Lifetime value (excluding cancelled/refunded) for this customer
                COALESCE(
                    SUM(o.sub_total_net) FILTER (WHERE o.lifecycle_status NOT IN ('cancelled', 'refunded'))
                    OVER (PARTITION BY o.customer_id),
                    0
                ) AS user_lifetime_value
            FROM shopwired.orders_deduplicated o
            JOIN shopwired.customers c ON c.external_id = o.customer_id
            SQL;
    }
}
