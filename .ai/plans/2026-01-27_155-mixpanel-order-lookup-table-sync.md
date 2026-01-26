# Plan: Mixpanel Order Lookup Table Sync

## Objective

Create a daily job that syncs order enrichment data to a Mixpanel lookup table, enabling Mixpanel reports to access customer/order metadata for any `order_id_hashed` event property.

## Background

Mixpanel lookup tables are "replace-only" (no incremental updates), so this job must:
1. Fetch ALL orders every sync (not just new ones)
2. Join with customer data for enrichment fields
3. Handle a bug period (Sept 1, 2025 - Jan 26, 2026) requiring duplicate hash rows

## Lookup Table Schema

| Column | Source | Type |
|--------|--------|------|
| `order_id_hashed` | `SHA256(order.reference + salt)` | Primary key |
| `user_is_credit` | `customers.is_credit_enabled` | Boolean |
| `user_account_created_at` | `customers.shopwired_created_at` | ISO 8601 |
| `user_company_name` | `customers.company_name` | String (nullable) |
| `user_is_trade` | `customers.is_trade` | Boolean |
| `order_is_first_order` | Computed: first non-cancelled/refunded order | Boolean |
| `user_total_orders` | Count excluding cancelled/refunded | Integer |
| `user_lifetime_value` | Sum of `sub_total_net` excluding cancelled/refunded | Decimal |

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| LTV source | `sub_total_net` (net) | Product value only, excludes VAT/shipping |
| Excluded from metrics | `cancelled`, `refunded` | Real customer value; `part_refunded` included |
| Cancelled order rows | Include with filtered metrics | Lookup always succeeds for any order |
| Bug period handling | 2 rows per order | Normal hash + fallback salt hash |
| Hash algorithm | `SHA256(reference + salt)` | Matches frontend `OrderAnalyticsHash` |
| Query location | In Provider (not Repository) | Denormalized reporting data, not domain entities |
| Sync frequency | Daily | LTV changes slowly; preserves rate limit |

## Phase 1: Configuration

### Files to Modify

| File | Changes |
|------|---------|
| `config/mixpanel.php` | Add `'order_enrichment'` to `lookup_table_ids` array |
| `.env.example` | Add `MIXPANEL_ORDER_LOOKUP_TABLE_ID` |

### Config Change
```php
// config/mixpanel.php
'lookup_table_ids' => [
    'utm_campaigns' => env('MIXPANEL_UTM_CAMPAIGN_LOOKUP_TABLE_ID'),
    'order_enrichment' => env('MIXPANEL_ORDER_LOOKUP_TABLE_ID'),  // NEW
],
```

---

## Phase 2: Create OrderLookupTableProvider

### File to Create
`app/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProvider.php`

### Dependencies
- `MixpanelConfig` (for analytics salt and bug period dates)
- `OrderAnalyticsHash` (for standard hash generation)
- `OrderAnalyticsHashMatcher` (for fallback salt generation)

### SQL Query (PostgreSQL with Window Functions)
```sql
SELECT
    o.reference,
    o.order_placed_at,
    o.lifecycle_status,
    c.is_credit_enabled AS user_is_credit,
    c.shopwired_created_at AS user_account_created_at,
    c.company_name AS user_company_name,
    c.is_trade AS user_is_trade,
    -- First order: ROW_NUMBER = 1 among non-cancelled/refunded orders
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
    -- Total orders (excluding cancelled/refunded)
    COUNT(*) FILTER (WHERE o.lifecycle_status NOT IN ('cancelled', 'refunded'))
        OVER (PARTITION BY o.customer_id) AS user_total_orders,
    -- Lifetime value (excluding cancelled/refunded)
    COALESCE(
        SUM(o.sub_total_net) FILTER (WHERE o.lifecycle_status NOT IN ('cancelled', 'refunded'))
        OVER (PARTITION BY o.customer_id),
        0
    ) AS user_lifetime_value
FROM shopwired.orders o
JOIN shopwired.customers c ON c.external_id = o.customer_id
```

### Provider Logic
```php
final readonly class OrderLookupTableProvider implements LookupTableProviderInterface
{
    private const string BUG_PERIOD_START = '2025-09-01';
    private const string BUG_PERIOD_END = '2026-01-26';

    public function __construct(
        private MixpanelConfig $config,
    ) {}

    public function getTableKey(): string
    {
        return 'order_enrichment';
    }

    public function getSourceName(): string
    {
        return 'ShopWired Orders';
    }

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

    public function fetchRows(): array
    {
        $results = DB::select($this->buildQuery());
        $rows = [];

        foreach ($results as $row) {
            // Standard hash row
            $hash = OrderAnalyticsHash::fromReference(
                (int) $row->reference,
                $this->config->analyticsSalt,
            )->value;

            $rows[] = $this->buildRow($hash, $row);

            // Bug period: add duplicate row with fallback salt
            if ($this->isInBugPeriod($row->order_placed_at)) {
                $fallbackSalt = 'alz-' . (new DateTimeImmutable($row->order_placed_at))->getTimestamp();
                $fallbackHash = hash('sha256', $row->reference . $fallbackSalt);
                $rows[] = $this->buildRow($fallbackHash, $row);
            }
        }

        return $rows;
    }

    private function buildRow(string $hash, object $row): array
    {
        return [
            $hash,
            $row->user_is_credit ? 'true' : 'false',
            $row->user_account_created_at,  // Already ISO 8601 from DB
            $row->user_company_name ?? '',
            $row->user_is_trade ? 'true' : 'false',
            $row->order_is_first_order ? 'true' : 'false',
            (string) $row->user_total_orders,
            number_format((float) $row->user_lifetime_value, 2, '.', ''),
        ];
    }

    private function isInBugPeriod(string $orderPlacedAt): bool
    {
        $date = new DateTimeImmutable($orderPlacedAt);
        $start = new DateTimeImmutable(self::BUG_PERIOD_START);
        $end = new DateTimeImmutable(self::BUG_PERIOD_END . ' 23:59:59');

        return $date >= $start && $date <= $end;
    }

    private function buildQuery(): string
    {
        // Return the SQL query above
    }
}
```

---

## Phase 3: Create Job

### File to Create
`app/Presentation/Jobs/Mixpanel/SyncOrderLookupTableJob.php`

### Pattern
Follow existing `SyncCampaignLookupTableJob`:
- Uses `SyncLookupTableUseCase` (existing, generic)
- Low queue (bulk background work)
- 3 retries with exponential backoff

```php
final class SyncOrderLookupTableJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 min (generous for expected < 30s)
    public array $backoff = [60, 300, 3600];

    public function __construct()
    {
        $this->onQueue('low'); // Bulk background work
    }

    public function handle(SyncLookupTableUseCase $useCase): void
    {
        // Same exception handling pattern as SyncCampaignLookupTableJob
    }
}
```

---

## Phase 4: Wire Up DI

### File to Modify
`app/Providers/AppServiceProvider.php` (or dedicated `MixpanelServiceProvider`)

### Binding Pattern
Follow existing `GoogleAdsServiceProvider` pattern - bind `SyncLookupTableUseCase` (not the provider interface) with a factory closure:

```php
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
use App\Infrastructure\Mixpanel\LookupTables\OrderLookupTableProvider;
use App\Infrastructure\Mixpanel\MixpanelConfig;
use App\Presentation\Jobs\Mixpanel\SyncOrderLookupTableJob;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

// Contextual binding: SyncOrderLookupTableJob gets SyncLookupTableUseCase with OrderLookupTableProvider
$this->app->when(SyncOrderLookupTableJob::class)
    ->needs(SyncLookupTableUseCase::class)
    ->give(
        static fn(Container $app): SyncLookupTableUseCase => new SyncLookupTableUseCase(
            new OrderLookupTableProvider(
                $app->make(MixpanelConfig::class),
            ),
            $app->make(MixpanelClientInterface::class),
            $app->make(LoggerInterface::class),
        ),
    );
```

**Note**: This pattern manually constructs the use case with the specific provider, matching how `SyncCampaignLookupTableJob` is wired in `GoogleAdsServiceProvider`.

---

## Phase 5: Schedule Job

### File to Modify
`routes/console.php` or `app/Console/Kernel.php`

### Schedule
```php
Schedule::job(new SyncOrderLookupTableJob())
    ->daily()
    ->at('04:00')
    ->withoutOverlapping()
    ->onOneServer();
```

---

## Phase 6: Testing

### Unit Tests
| Test | Location |
|------|----------|
| Provider returns correct headers | `tests/Unit/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProviderTest.php` |
| Bug period detection | Same file |
| Hash generation matches expected | Same file |
| Row formatting (booleans, decimals) | Same file |

### Integration Tests
| Test | Location |
|------|----------|
| Full fetchRows() with seeded data | `tests/Feature/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProviderTest.php` |
| Job dispatches and calls use case | `tests/Feature/Presentation/Jobs/Mixpanel/SyncOrderLookupTableJobTest.php` |

---

## Files Summary

### Create
| File | Purpose |
|------|---------|
| `app/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProvider.php` | Data fetching + transformation |
| `app/Presentation/Jobs/Mixpanel/SyncOrderLookupTableJob.php` | Queue job |
| `tests/Unit/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProviderTest.php` | Unit tests |
| `tests/Feature/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProviderTest.php` | Integration tests |

### Modify
| File | Changes |
|------|---------|
| `config/mixpanel.php` | Add lookup table ID |
| `.env.example` | Add env var |
| `app/Providers/AppServiceProvider.php` | DI binding |
| `routes/console.php` | Schedule job |
| `app/Presentation/Jobs/Mixpanel/SyncCampaignLookupTableJob.php` | Add `$this->onQueue('low')` in constructor (consistency fix) |

---

## Performance Estimates

| Metric | Estimate |
|--------|----------|
| Orders | ~70,000 |
| Bug period orders | ~30,000 (Sept 2025 - Jan 2026) |
| Total rows | ~100,000 (70k + 30k duplicates) |
| Hash generation | ~50ms (100k hashes) |
| SQL query | ~2-5 seconds (window functions, single pass) |
| CSV size | ~8-10 MB (well under 100MB limit) |
| Mixpanel upload | ~5-10 seconds |
| Total job time | < 30 seconds |

---

## Verification

### After Implementation
```bash
make lint                    # PHPStan, Pint, PHPArkitect, Deptrac
make test                    # All tests pass
```

### Manual Verification
```bash
php artisan tinker
>>> app(OrderLookupTableProvider::class)->fetchRows()
# Verify row count, format, bug period duplicates
```

### Production Deployment
1. Add `MIXPANEL_ORDER_LOOKUP_TABLE_ID` to production env
2. Deploy code
3. Run job manually once: `php artisan queue:work --once`
4. Verify in Mixpanel UI: Data Management → Lookup Tables

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Memory usage with 100k rows | ~10MB max; acceptable for queued job |
| Query timeout | Window functions are single-pass; indexed columns |
| Mixpanel rate limit (100/day) | Daily schedule uses 1 of 100 calls |
| Bug period date drift | Constants in Provider; easy to adjust |
| Missing customer join | `JOIN` ensures only orders with customers included |

---

## Open Questions

None remaining - all decisions made during investigation.

---

## Review Notes (2026-01-27)

Issues caught during `/check` review and corrected:

| Severity | Issue | Fix Applied |
|----------|-------|-------------|
| CRITICAL | SQL query used undefined `o2` alias | Changed to `o.lifecycle_status` and `o.sub_total_net` |
| HIGH | DI binding used wrong approach | Changed to bind `SyncLookupTableUseCase` with factory closure |
| MEDIUM | Missing `$timeout` property | Added `public int $timeout = 300` |
| MEDIUM | Queue routing pattern | Added constructor with `$this->onQueue('low')` |
| LOW | Existing job inconsistency | Added `SyncCampaignLookupTableJob` to modify list for queue consistency |
