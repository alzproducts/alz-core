# ALZ-CORE-A9 — Make `OrderShipping.name` nullable

**Sentry issues:**
- `ALZ-CORE-A9` (parent) — `InvalidApiResponseException` from `ShopwiredOrderWebhookParser::parseOrder`. Root TypeError: `OrderShippingResponse::__construct(): Argument #1 ($name) must be of type string, null given`. First seen 2026-04-28T12:53Z (~25h before this session).
- `ALZ-CORE-AA` (downstream) — `RecordNotFoundException` from `EloquentOrderRepository::updateStatus`. Caused (in part) by orders that fail A9 never being persisted, then `status_changed` webhooks finding no row.

**Confirmed via prod DB query:** orders `11713302` and `11713487` are missing from `shopwired.orders`.

**User insight (manual ShopWired audit):**
- `11710361` — confirmed null shipping method name in ShopWired (legitimate: staff can place an order without selecting a shipping method).
- `11713302` — has a shipping method name in ShopWired, so this order's absence from our DB is **not** caused by A9. There is at least one additional, separate cause.

**Plan agreed with user:**
1. Fix A9 (this log).
2. Re-evaluate AA after deploy. Investigate any remaining causes for missing orders separately.
3. Self-heal in `UpdateOrderStatusUseCase` (catch RecordNotFoundException → dispatch sync) **deferred**.

## Decisions

### D1 — `OrderShipping.name` becomes nullable on Domain VO and DTO
- Domain VO: `App\Domain\Catalog\Order\ValueObjects\OrderShipping::$name: ?string`.
- DTO: `App\Infrastructure\Shopwired\Responses\OrderShippingResponse::$name: ?string`.
- Why: real-world data state — staff-entered orders may have no shipping method.
- Code unchanged for ~14 days; A9 started 25h ago → root cause is upstream data variation, not a regression on our side.

### D2 — Coerce `''` → `null` at the DTO boundary
- In `OrderShippingResponse::toDomain()` (or whatever maps the response into the Domain VO), normalise empty string to `null` before constructing the Domain VO.
- Why: matches the existing convention in `OrderResponse::toDomain()` for `trackingUrl` (line 180) and `invoiceUrl` (line 181). One canonical representation in the DB. No need for a `nullOrStringNotEmpty` assertion in Domain.

## D3 — PR scope: **Minimal A9 fix only**

User chose minimal scope. We do **NOT** touch `OrderModelMapper::buildShipping` (read path) or the write path defaulting `shipping_charge_net` to 0.0. Case B rows will round-trip lossily on read (OrderShipping VO becomes null), but `Order.shipping_total_net` is preserved. Worth revisiting in a separate PR if downstream consumers turn out to care.

## Open: read-side mapper / round-trip cleanliness (deferred — see D3)

User pushed back on adding a "validity check" to `buildShipping`. Looking at the write path (`OrderModelMapper:191-194`):

```
'shipping_id'         => $order->shipping?->id,                // null when no shipping
'shipping_method'     => $order->shipping?->name,              // null when no shipping or null name
'shipping_charge_net' => $order->shipping !== null ? $order->shipping->chargeNet : 0.0,  // ⚠ 0.0 when no shipping
'shipping_vat_rate'   => $order->shipping?->vatRate,           // null when no shipping
```

`shipping_charge_net` defaults to `0.0` when there's no shipping at all (case A) — that conflates "no shipping" with "free shipping". This is a pre-existing code smell that prevents using `shipping_charge_net` as the read-side "shipping exists" discriminator.

Three cases to disambiguate on read:
- **A.** Digital order, no shipping line (`shipping = []` on wire) → all shipping_* should be null → `Order.shipping = null`.
- **B.** Manual order, shipping line present but null name (the A9 case) → name null, charge/vat populated → `Order.shipping = OrderShipping(name: null, ...)`.
- **C.** Normal shipping line → all populated → full `OrderShipping`.

Discriminator candidates on read:
- `shipping_method !== null` — current gate; collapses A+B → A. **Wrong** under nullable name.
- `shipping_charge_net !== null` — broken because write-path defaults to `0.0` for case A.
- `shipping_vat_rate !== null` — currently the cleanest signal: null only in case A.

**Two scopes for this PR:**
1. **Minimal A9 fix.** Make name nullable on DTO + Domain VO + coerce `''`→null; leave write/read mapper as-is. Lossy round-trip for case B (OrderShipping VO is dropped on read). `Order.shipping_total_net` is preserved on the Order itself, so order-level financial reporting still works.
2. **Symmetric write/read.** Also fix the write path so case A writes `shipping_charge_net = null` (not 0.0), and change the read gate to use `shipping_vat_rate !== null` (or any non-null shipping column). Lossless round-trip. Larger PR; touches mapper read + write.

Decision deferred — see /grill-me Q4.

## D4 — DTO comment update

The comment at `OrderShippingResponse.php:15` ("Always embedded in Standard/Detail modes - all fields non-nullable.") is now wrong. Replace with: name is nullable when staff create an order without selecting a shipping method.

## D5 — Tests: T2 only (T3 deferred)

User picked T2 + T3 in /grill-me. After exploring the test suite I found:
- No existing JSON fixtures for an OrderResponse webhook payload.
- `OrderResponse` has ~30 required fields. Building a fixture for T3 (parser-level) would be expensive.
- `OrderShippingResponse::from(...)` already exercises Spatie's full deserialisation pipeline (SnakeCaseMapper, type cast, constructor) — i.e. **T2 covers the unit that actually broke**.

**Pragmatic decision:** comprehensive T2 only. T3 is largely redundant: if `OrderShippingResponse::from(['name' => null, 'value' => ..., 'vat_rate' => ...])` works, the parser path works. Skipping T3 saves a 30-field fixture for marginal value.

If we discover the parser-level test is needed (e.g. a future bug in how OrderResponse's `DataCollectionOf(OrderShippingResponse::class)` re-hydrates), add it then with full context.

- **T2** — `OrderShippingResponseTest` (new file under `tests/Unit/Infrastructure/Shopwired/Responses/`). Cases: `from()` accepts `name: null`; `from()` accepts `name: ''` and `toDomain()` coerces to `null`; `from()` accepts a normal string name and `toDomain()` passes it through unchanged.

## D6 — Backfill: B4 (deploy + immediate one-shot resync)

After PR merges and prod deploys:

1. Open a tinker session via `railway ssh -s alz-core-worker php artisan tinker` (interactive)
2. Run:
   ```php
   app(\App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface::class)
       ->dispatchOrderSync(\App\Domain\ValueObjects\IntId::from(11710361));
   app(\App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface::class)
       ->dispatchOrderSync(\App\Domain\ValueObjects\IntId::from(11713302));
   app(\App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface::class)
       ->dispatchOrderSync(\App\Domain\ValueObjects\IntId::from(11713487));
   ```
   Verify ShopwiredSyncDispatcherInterface is the right interface — check the existing pattern in UpdateOrderStatusUseCase where it's called.
3. Watch Horizon / Sentry for ~10 min for the sync jobs to complete. Confirm the rows appear in `shopwired.orders`.
4. Re-evaluate AA (ALZ-CORE-AA) — should stop firing for these three orders. If it still fires for *other* orders, that's the separate cause we suspected (11713302 has a shipping name, so something else is going on).

## Implementation order

1. Update Domain VO `OrderShipping.php` — `name: ?string`. Update docblock.
2. Update DTO `OrderShippingResponse.php` — `name: ?string`, replace the misleading comment, coerce `''` → `null` in `toDomain()`.
3. Add T2 test for DTO coercion.
4. Add T3 parser regression test (the irreplaceable one).
5. Open PR via `/pr` skill (per AGENTS.md — never `gh pr create` directly).
6. After merge + deploy: run the tinker resync from D6.
7. Resolve A9 in Sentry and watch AA. If AA persists for orders not on the backfill list, open a follow-up.


## Files likely to change

- `app/Domain/Catalog/Order/ValueObjects/OrderShipping.php` — `name: ?string`.
- `app/Infrastructure/Shopwired/Responses/OrderShippingResponse.php` — `name: ?string`, update comment, coerce `''` → null in `toDomain()`.
- (Maybe) `app/Infrastructure/Shopwired/Mappers/OrderModelMapper.php` — `buildShipping` handling for null name.
- `tests/Unit/Domain/Catalog/Order/ValueObjects/OrderShippingTest.php` — new tests for nullable name.
- (Maybe) Parser/integration tests under `tests/Feature/` for the webhook + empty-string coercion.

## DB schema observation

- `shopwired.orders.shipping_method` (= the name) is **already nullable** (migration `2026_01_11_033928_create_shopwired_orders_table.php:79`). No migration required.
