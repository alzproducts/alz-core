<?php

declare(strict_types=1);

/*
 * alz-core verify bench helper, driven by verify.sh bench-load / bench-writers. See ../features/products-perf.md.
 * Usage: php bench.php <sub> [--key value]...   (cwd = the repo worktree, env = the run env)
 *   runtime                         Prints uninstrumented=yes|no (xdebug and pcov off)
 *   writers                         Prints the background writer schedule as JSON lines: kind interval delay
 *   shapes --out F                  Writes the list request mix's URIs, derived from the seed, as JSON
 *   writer --kind K --stop-file F --deadline T --result F
 *                                   Runs one background writer on its schedule until F exists or epoch T passes
 *   load --port P --shapes F --log F --warmup N --requests N --rate R --result F
 *                                   Paced list requests (bypass secret from VERIFY_BENCH_BYPASS); p99 from the log
 *   time-writers --runs N --result F
 *                                   Times the variation replace and the stock mirror, N runs each
 *
 * Writers and timings call repository methods directly and never reach ShopWired or Linnworks. They mutate the
 * run's throwaway database and restore nothing: launch a fresh run for each bench journey.
 * Classes are resolved by name so this file loads at any commit; a missing method surfaces as a writer failure.
 */

const MEASURED_PATH = '/api/products';
const LOG_FLUSH_SECONDS = 3;

/*
 * Background write load per writer: [interval seconds (null = once per run), first write after seconds].
 * "once" writers fire ONCE_DELAY seconds after the warm-up ends, so they land inside the measured window.
 */
const WRITERS = [
    'product_webhook_upsert' => [2.0, 1.0],
    'stock_mirror' => [30.0, 5.0],
    'cost_price_update' => [60.0, 10.0],
    'supplier_sync' => [60.0, 40.0],
    'category_webhook' => [300.0, 15.0],
    'daily_sync_variation_replace' => [null, 60.0],
    'popularity_snapshot' => [null, 90.0],
];

/* One slot per 5% of traffic: 70% dashboard, 10% default page, 5% each of page 2, filtered, sorted and searched. */
const MIX = [
    'dashboard', 'default_page', 'dashboard', 'page_2', 'dashboard', 'filtered', 'dashboard', 'dashboard',
    'sorted', 'dashboard', 'default_page', 'dashboard', 'searched', 'dashboard', 'dashboard', 'dashboard',
    'dashboard', 'dashboard', 'dashboard', 'dashboard',
];

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "bench.php: {$message}\n");
    exit($code);
}

/** @return array<string, string> */
function options(array $argv): array
{
    $out = [];
    for ($i = 0; $i < count($argv); $i += 2) {
        if (! str_starts_with($argv[$i], '--') || ! isset($argv[$i + 1])) {
            fail("bad argument: {$argv[$i]}", 64);
        }
        $out[substr($argv[$i], 2)] = $argv[$i + 1];
    }

    return $out;
}

function opt(array $options, string $key): string
{
    return $options[$key] ?? fail("missing --{$key}", 64);
}

function writeJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function nowMs(): float
{
    return hrtime(true) / 1e6;
}

/** Nearest-rank percentile. */
function percentile(array $values, float $percent): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $rank = (int) ceil($percent / 100 * count($values));

    return (float) $values[max($rank, 1) - 1];
}

function uninstrumented(): bool
{
    $xdebug = function_exists('xdebug_info') && xdebug_info('mode') !== [];

    return ! $xdebug && ! filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL);
}

function boot(): Illuminate\Contracts\Foundation\Application
{
    if (! uninstrumented()) {
        fail('xdebug or pcov is active; timings would measure the instrumentation (relaunch the run)');
    }
    require getcwd() . '/vendor/autoload.php';
    $app = require getcwd() . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    return $app;
}

function db(Illuminate\Contracts\Foundation\Application $app): Illuminate\Database\Connection
{
    return $app->make('db')->connection();
}

// ---- request mix ----

function cmdShapes(array $o): void
{
    $app = boot();
    $category = db($app)->scalar(<<<'SQL'
        SELECT c.value::bigint FROM shopwired.products AS p, jsonb_array_elements_text(p.category_ids) AS c(value)
        WHERE p.is_active GROUP BY 1 ORDER BY count(*) DESC, 1 LIMIT 1
        SQL);
    $word = db($app)->scalar(<<<'SQL'
        SELECT lower(w) FROM shopwired.products AS p, regexp_split_to_table(p.title, '[^[:alpha:]]+') AS w
        WHERE p.is_active AND length(w) >= 6 GROUP BY 1 ORDER BY count(*) DESC, 1 LIMIT 1
        SQL);
    if ($category === null || $word === null) {
        fail('the seed has no active product with a category and a 6-letter title word');
    }
    writeJson(opt($o, 'out'), [
        'dashboard' => '/api/products?per_page=1000&page=1&include=stock',
        'default_page' => '/api/products',
        'page_2' => '/api/products?per_page=500&page=2',
        'filtered' => '/api/products?category_id=' . $category,
        'sorted' => '/api/products?sort_by=price&sort_direction=desc',
        'searched' => '/api/products?search=' . rawurlencode((string) $word),
    ]);
}

// ---- background writers ----

/** @return Closure(): void */
function writerFor(string $kind, Illuminate\Contracts\Foundation\Application $app): Closure
{
    $products = $app->make('App\Infrastructure\Shopwired\Repositories\EloquentProductRepository');

    return match ($kind) {
        'product_webhook_upsert' => (static function () use ($products): Closure {
            $ids = $products->getAllExternalIds();
            sort($ids);
            $next = 0;

            return static function () use ($products, $ids, &$next): void {
                $id = $ids[$next++ % count($ids)];
                $products->saveFromWebhook($products->getProduct(App\Domain\ValueObjects\IntId::from($id)), ['variations']);
            };
        })(),
        'stock_mirror' => stockMirror($app),
        'cost_price_update' => costPriceUpdate($app),
        'supplier_sync' => (static function () use ($app): Closure {
            $repository = $app->make('App\Application\Contracts\Linnworks\StockItemRepositoryInterface');
            $pages = array_chunk(array_map(static fn(object $r): string => $r->id, db($app)->select('SELECT id FROM linnworks.stock_items ORDER BY stock_item_id')), 200);
            $next = 0;

            return static function () use ($repository, $pages, &$next): void {
                $page = $pages[$next++ % count($pages)];
                $repository->saveMany(array_values('App\Infrastructure\Linnworks\Models\StockItemModel'::query()
                    ->with(['suppliers', 'extendedProperties'])->findMany($page)->sortBy('stock_item_id')
                    ->map(static fn(object $model): object => $model->toDomain())->all()));
            };
        })(),
        'category_webhook' => (static function () use ($app): Closure {
            $categories = $app->make('App\Infrastructure\Shopwired\Repositories\EloquentCategoryRepository');
            $id = db($app)->scalar('SELECT external_id FROM shopwired.categories ORDER BY external_id LIMIT 1');

            return static function () use ($categories, $id): void {
                $categories->saveFromWebhook('App\Infrastructure\Shopwired\Models\CategoryModel'::query()->where('external_id', $id)->firstOrFail()->toDomain());
            };
        })(),
        'daily_sync_variation_replace' => variationReplace($app),
        'popularity_snapshot' => static function () use ($app): void {
            $today = (int) db($app)->scalar('SELECT (SELECT count(*) FROM catalog.product_popularity_snapshots WHERE snapshot_date = CURRENT_DATE) + (SELECT count(*) FROM catalog.sku_popularity_snapshots WHERE snapshot_date = CURRENT_DATE)');
            if ($today > 0) {
                fwrite(STDOUT, "popularity_snapshot skipped: snapshots dated today already exist\n");

                return;
            }
            $app->make('App\Infrastructure\Catalog\Repositories\ProductPopularityRankingSnapshotRepository')->writeSnapshotForToday();
            $app->make('App\Infrastructure\Catalog\Repositories\SkuPopularityRankingSnapshotRepository')->writeSnapshotForToday();
        },
        default => fail("unknown writer: {$kind}", 64),
    };
}

/** The daily sync's per-product save, which deletes and re-inserts each product's variations; loading is untimed. */
function variationReplace(Illuminate\Contracts\Foundation\Application $app, bool $preload = false): Closure
{
    $products = $app->make('App\Infrastructure\Shopwired\Repositories\EloquentProductRepository');
    $load = static function () use ($products): array {
        $ids = $products->getAllExternalIds();
        sort($ids);

        return array_map(static fn(int $id): object => $products->getProduct(App\Domain\ValueObjects\IntId::from($id)), $ids);
    };
    $catalogue = $preload ? $load() : null;

    return static function () use ($products, $load, $catalogue): void {
        foreach ($catalogue ?? $load() as $product) {
            $products->save($product);
        }
    };
}

/** The stock mirror over every SKU, alternating each quantity between its original value and one more. */
function stockMirror(Illuminate\Contracts\Foundation\Application $app): Closure
{
    $stock = $app->make('App\Infrastructure\Shopwired\Repositories\EloquentProductStockRepository');
    $original = $stock->getAllStockLevels();
    $bumped = array_map(static fn(object $l): object => new App\Domain\Inventory\ValueObjects\ItemStockLevel($l->sku, $l->quantity + 1), $original);
    $flip = false;

    return static function () use ($stock, $original, $bumped, &$flip): void {
        $flip = ! $flip;
        $stock->updateStockLevels($flip ? $bumped : $original);
    };
}

/** A cost-price bulk UPDATE and change-log write over 25 SKUs of the most common default supplier. */
function costPriceUpdate(Illuminate\Contracts\Foundation\Application $app): Closure
{
    $suppliers = $app->make('App\Infrastructure\Linnworks\Repositories\EloquentStockItemSupplierRepository');
    $changeLog = $app->make('App\Infrastructure\Catalog\Repositories\EloquentCostPriceChangeLogRepository');
    $sample = db($app)->select(<<<'SQL'
        SELECT si.item_number AS sku, s.supplier_id, s.supplier_name, s.purchase_price::float8 AS purchase_price
        FROM linnworks.stock_item_suppliers AS s JOIN linnworks.stock_items AS si ON si.stock_item_id = s.stock_item_id
        WHERE s.is_default AND s.purchase_price IS NOT NULL AND si.item_number IS NOT NULL
          AND s.supplier_name = (SELECT supplier_name FROM linnworks.stock_item_suppliers WHERE is_default AND purchase_price IS NOT NULL
                                 GROUP BY supplier_name ORDER BY count(*) DESC, supplier_name LIMIT 1)
        ORDER BY si.item_number LIMIT 25
        SQL);
    if ($sample === []) {
        fail('cost_price_update: the seed has no default supplier with a purchase price');
    }
    $flip = false;

    return static function () use ($suppliers, $changeLog, $sample, &$flip): void {
        $flip = ! $flip;
        $prices = [];
        $changes = [];
        foreach ($sample as $row) {
            $old = $flip ? $row->purchase_price : round($row->purchase_price + 0.01, 4);
            $new = $flip ? round($row->purchase_price + 0.01, 4) : $row->purchase_price;
            $prices[$row->sku] = $new;
            $changes[] = new App\Application\Catalog\Commands\CostPriceChangeCommand(
                sku: App\Domain\Catalog\Product\ValueObjects\Sku::fromTrusted($row->sku),
                supplierId: App\Domain\ValueObjects\Guid::fromTrusted($row->supplier_id),
                supplierName: $row->supplier_name,
                oldPrice: App\Domain\Shared\Money\ValueObjects\Money::exclusive($old),
                newPrice: App\Domain\Shared\Money\ValueObjects\Money::exclusive($new),
            );
        }
        $suppliers->bulkUpdatePurchasePrices($sample[0]->supplier_name, $prices);
        $changeLog->record($changes);
    };
}

function cmdWriter(array $o): void
{
    $kind = opt($o, 'kind');
    [$interval, $delay] = WRITERS[$kind] ?? fail("unknown writer: {$kind}", 64);
    $stopFile = opt($o, 'stop-file');
    $deadline = (float) opt($o, 'deadline');
    $delay += (float) ($o['once-offset'] ?? 0) * ($interval === null ? 1 : 0);
    $result = ['kind' => $kind, 'writes' => 0, 'write_ms' => [], 'error' => null];
    try {
        $write = writerFor($kind, boot());
        $nextAt = microtime(true) + $delay;
        while (! is_file($stopFile) && microtime(true) < $deadline) {
            if (microtime(true) >= $nextAt) {
                $startedAt = nowMs();
                $write();
                $result['write_ms'][] = round(nowMs() - $startedAt, 1);
                $result['writes']++;
                $nextAt = $interval === null ? INF : $nextAt + $interval;
            }
            usleep(200_000);
        }
    } catch (Throwable $e) {
        $result['error'] = get_class($e) . ': ' . $e->getMessage();
    }
    writeJson(opt($o, 'result'), $result);
    exit($result['error'] === null ? 0 : 1);
}

// ---- load ----

function logOffset(string $log): int
{
    clearstatcache(true, $log);

    return is_file($log) ? (int) filesize($log) : 0;
}

/** Octane request lines carry no timestamp or query string, so a window is a byte range of the log. */
function logDurations(string $log, int $from, int $to): array
{
    $chunk = (string) file_get_contents($log, false, null, $from, max($to - $from, 0));
    $durations = [];
    $statuses = [];
    foreach (explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', $chunk)) as $line) {
        if (preg_match('#^\s*(\d{3})\s+GET\s+(/\S*)\s.*?(\d+(?:\.\d+)?)\s*ms\s*$#', $line, $m) === 1 && $m[2] === MEASURED_PATH) {
            $durations[] = (float) $m[3];
            $statuses[$m[1]] = ($statuses[$m[1]] ?? 0) + 1;
        }
    }

    return [$durations, $statuses];
}

function curlHandle(string $url, string $secret): CurlHandle
{
    $h = curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Local-Bypass: ' . $secret],
    ]);

    return $h;
}

/** Sends URIs at a fixed rate without waiting for responses; returns status per request (0 = transport failure). */
function sendPaced(string $base, array $uris, float $rate, string $secret): array
{
    $multi = curl_multi_init();
    $handles = [];
    $startedAt = microtime(true);
    foreach ($uris as $i => $uri) {
        $h = curlHandle($base . $uri, $secret);
        curl_multi_add_handle($multi, $h);
        $handles[$i] = $h;
        $due = $startedAt + (($i + 1) / $rate);
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.002);
        } while (microtime(true) < $due);
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.05);
    } while ($running > 0);
    $statuses = [];
    foreach ($handles as $h) {
        $statuses[] = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $h);
    }

    return $statuses;
}

function cmdLoad(array $o): void
{
    $secret = getenv('VERIFY_BENCH_BYPASS') ?: fail('VERIFY_BENCH_BYPASS is not set', 64);
    $base = 'http://127.0.0.1:' . opt($o, 'port');
    $shapes = json_decode((string) file_get_contents(opt($o, 'shapes')), true, flags: JSON_THROW_ON_ERROR);
    $log = opt($o, 'log');
    $warmup = (int) opt($o, 'warmup');
    $requests = (int) opt($o, 'requests');
    $rate = (float) opt($o, 'rate');
    if ($requests < 1 || $rate <= 0) {
        fail('--requests and --rate must be positive', 64);
    }

    foreach ($shapes as $name => $uri) {
        $h = curlHandle($base . $uri, $secret);
        $body = json_decode((string) curl_exec($h), true);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        if ($status !== 200 || ! is_array($body['data'] ?? null) || $body['data'] === []) {
            fail("probe {$name} ({$uri}) returned status {$status} with no rows; the mix needs every shape to return rows");
        }
    }
    fwrite(STDOUT, "probe ok: every shape returns rows\n");

    $uri = static fn(int $i): string => $shapes[MIX[$i % count(MIX)]];
    sendPaced($base, array_map($uri, range(0, max($warmup - 1, 0))), $rate, $secret);
    // intentional: Octane logs a request after sending its response, so the last warm-up line can still be pending.
    sleep(LOG_FLUSH_SECONDS);
    $from = logOffset($log);
    fwrite(STDOUT, "warm-up done ({$warmup}); measuring {$requests}\n");
    $measuredAt = gmdate('c');
    $statuses = sendPaced($base, array_map($uri, range($warmup, $warmup + $requests - 1)), $rate, $secret);
    sleep(LOG_FLUSH_SECONDS);
    [$durations, $serverStatuses] = logDurations($log, $from, logOffset($log));

    $mix = array_count_values(MIX);
    writeJson(opt($o, 'result'), [
        'measured_at' => $measuredAt,
        'requests_sent' => $requests,
        'warmup' => $warmup,
        'rate_per_second' => $rate,
        'server_lines' => count($durations),
        'server_statuses' => $serverStatuses,
        // intentional: an object even when the only key is 0, which json_encode would otherwise emit as a list.
        'client_statuses' => (object) array_count_values($statuses),
        'client_transport_failures' => count(array_filter($statuses, static fn(int $s): bool => $s === 0)),
        'p50_ms' => percentile($durations, 50),
        'p95_ms' => percentile($durations, 95),
        'p99_ms' => percentile($durations, 99),
        'max_ms' => $durations === [] ? null : max($durations),
        'mix_percent' => array_map(static fn(int $n): int => intdiv($n * 100, count(MIX)), $mix),
        'shapes' => $shapes,
    ]);
}

// ---- writer timing ----

function cmdTimeWriters(array $o): void
{
    $app = boot();
    $runs = (int) opt($o, 'runs');
    $writers = [
        'variation_replace' => variationReplace($app, preload: true),
        'stock_mirror' => stockMirror($app),
    ];
    $out = ['measured_at' => gmdate('c'), 'runs' => $runs, 'writers' => []];
    foreach ($writers as $name => $write) {
        $durations = [];
        for ($run = 1; $run <= $runs; $run++) {
            $startedAt = nowMs();
            $write();
            $durations[] = round(nowMs() - $startedAt, 1);
            fwrite(STDOUT, "{$name} run {$run}: " . end($durations) . " ms\n");
        }
        $out['writers'][$name] = ['runs_ms' => $durations, 'median_ms' => percentile($durations, 50)];
    }
    $out['products'] = count($app->make('App\Infrastructure\Shopwired\Repositories\EloquentProductRepository')->getAllExternalIds());
    $out['stock_levels'] = count($app->make('App\Infrastructure\Shopwired\Repositories\EloquentProductStockRepository')->getAllStockLevels());
    writeJson(opt($o, 'result'), $out);
}

$sub = $argv[1] ?? fail('missing subcommand', 64);
$o = options(array_slice($argv, 2));
match ($sub) {
    'runtime' => print('uninstrumented=' . (uninstrumented() ? 'yes' : 'no') . "\n"),
    'writers' => array_map(static fn(string $k, array $w): int => print("{$k} " . ($w[0] ?? 'once') . " {$w[1]}\n"), array_keys(WRITERS), WRITERS),
    'shapes' => cmdShapes($o),
    'writer' => cmdWriter($o),
    'load' => cmdLoad($o),
    'time-writers' => cmdTimeWriters($o),
    default => fail("unknown subcommand: {$sub}", 64),
};
