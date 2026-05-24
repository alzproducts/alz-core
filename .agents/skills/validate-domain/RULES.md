# VO Placement and Design Rules

Apply Section A first to decide whether the class belongs in Domain. If A passes, apply Section B to evaluate design quality. Apply the Layered VOs section only when the class participates in a strictness hierarchy.

## Section A — Does this VO belong in the Domain layer?

**Before applying A1–A5:** check whether another class under `app/Domain/` holds this one (property type, constructor parameter, or `list<X>` element). If so, the EXCEPTION in `.claude/rules/domain-class-placement.md` applies — A1 and A2 are satisfied automatically and the class stays in Domain. Continue to A3–A5; they still apply.

**A1. Encodes at least one invariant OR carries distinct domain identity.**
- ✅ `Email` (non-empty), `Sku` (format), `Money` (amount ≥ 0 + tax type), `OrderId` (typed identity even without a check).
- ❌ A class whose name describes a structural shape, not a business concept (generic `NonEmptyString`).

**A2. A downstream consumer trusts the invariant/identity without re-checking** — i.e. the VO converts caller vigilance into type-system enforcement.
- ✅ Removing the VO would force `!empty($email)` checks at call sites, or callers passing the wrong primitive in the wrong slot.
- ❌ Nothing ever assumes the rule and the type isn't distinguishing anything — defensive paranoia, not enforcement.

**A3. Returns only native PHP types, other Domain VOs, or Domain enums from public methods — no wire-format commitments.**
- ✅ `float`, `string`, `int`, `bool`, `DateTimeImmutable`, enum instances, other Domain VOs (`Order::shippingAddress(): Address`), arrays of those, generic format strings (`"12.00"`).
- ❌ Wire-format strings/arrays: snake_case keys, `->value` on enums in returns, ATOM-formatted dates, locale-baked currency (`"£12.00 GBP"`). Those belong in Presentation.

**A4. No framework dependencies — PHP stdlib and `webmozart/assert` only.**
- ✅ Plain classes, PHP built-ins, `Webmozart\Assert\Assert`.
- ❌ `Illuminate\*` parents, Spatie LaravelData, Eloquent, Carbon, any SDK.

**A5. No I/O or external state in methods.** Public methods are pure functions of object state + method arguments. The caller is responsible for time, randomness, and external lookups.
- ✅ Pure computation, native math, comparisons, projections, deriving values from already-stored state.
- ❌ Filesystem (`file_*`, `fopen`, `glob`), network (`curl_*`, `fsockopen`), database (`PDO`, `DB::`), current time (`time()`, `microtime()`, `new DateTime()` without an argument, `Carbon::now()`), randomness (`mt_rand`, `random_int`, `uniqid`), environment (`getenv`, `$_ENV`, `$_SERVER`), static mutable state (private static used as cache/counter — also unsafe under Octane).
- Pass `now` and similar context in as constructor arguments — Domain doesn't decide *when*, the caller does.

### Redirect map (Section A failures)

| Failing rule | Likely correct home |
|---|---|
| A1 — no invariant, no identity; pure label/enum | `App\Application\{Feature}\Enums` (e.g. `AdPlatform`) |
| A1 — data shape crossing a layer | `App\Application\{Feature}\DTOs` |
| A1 — write-operation parameter bundle | `App\Application\{Feature}\Commands` |
| A2 — invariant exists but no consumer relies on it | Delete the VO, or move the invariant to boundary validation (FormRequest, Laravel Validator) |
| A3 — wire-format leak | Move serialisation to a `Resource`/DTO in Presentation |
| A4 — framework dependency | `App\Application` (Spatie DTOs) or `App\Infrastructure` (SDK-coupled models) |
| A5 — I/O, `now()` calls, or static mutable state | Move impure work to Application (use case) or Infrastructure (gateway/client); pass deterministic values into Domain as arguments |

## Section B — Is the VO well-shaped?

**B1. Immutable.** Class is `readonly` (or all properties are `readonly`). No public setters, no mutating methods. Operations that "change" the value return a new instance.
- ✅ `final readonly class Money` + `add(Money): Money` returns new.
- ❌ `$money->setAmount(10)`, public-write properties.

**B2. All construction paths enforce all invariants — no validity-mode methods, no trusted backdoors.**
- ✅ Money has multiple invariants (amount ≥ 0, currency string, valid `TaxType`), all enforced at construction or by the type system. Every named constructor (`inclusive()`, `exclusiveFromString()`, `nonZeroOrNull()`) routes through the same private `__construct` so all paths run the same checks. None of the invariants are caller-selectable.
- ❌ `Email::isStrictlyValid()` / `Email::isLooselyValid()` — split into separate types and compose.
- ❌ A `Money::fromTrustedRaw(float)` factory that skips the assertion "because the caller already validated" — if *any* construction path can produce an invariant-violating VO, the invariant stops being a guarantee. Persistence rehydration counts: re-run invariants on the way back in.

**B3. Public *instance* methods operate on already-valid canonical state. Public *static* methods may exist as domain rules taking raw inputs.**
- ✅ Instance: projections (`toGross()`), derived queries (`isZero()`, `isLessThan()`), arithmetic (`add(): self`).
- ✅ Static: named constructors (`Money::inclusive()`), domain rules taking raw inputs (`Money::isVatRoundTripSafe(float, TaxRate): bool`).
- ❌ `isValid()`, `isUsable()`, `check()` on instances — anything whose name implies "the object might not be safe to use". The test: does the method *expose a fact about valid state*, or *gate whether callers can trust the state*?

**B4. Constructor and factory parameters use Domain types over primitives where available.**
- ✅ `function __construct(public Sku $sku, public Money $price, public Weight $weight)` — composes Domain types so invariants propagate up.
- ❌ `function __construct(public string $sku, public float $price, public float $weight)` when `Sku`, `Money`, and `Weight` exist in Domain — primitives bypass the type system and defeat the point of having VOs.
- Canonical reference: the "Native Domain Types" table in `app/Domain/CLAUDE.md` (Money/Sku/Gtin/Weight/Dimensions/TaxType/TaxRate/DateRange/IntId/Guid).

## Layered VOs (only when stacking strictness)

If you have variants like `Email → StrictEmail → VerifiedEmail`:

- **Promotion is explicit** — going from looser to stricter requires a constructor that runs the additional check. No mode flags, no runtime mutation, no implicit upcasting.
- **Prefer composition over inheritance** — `StrictEmail` wraps `Email` rather than extending it. Each layer owns one rule; new layers slot in without rearranging a class hierarchy.

Skip this section for VOs without strictness levels (Money, Sku, Weight).

## Related rules

Some Domain-layer concerns are owned by separate path-scoped rules that auto-load on file open. This skill should defer to them rather than duplicate their content:

- **Domain class placement gate** — three-question gate (invariant / business logic / domain reasoning) plus the EXCEPTION clause for child types held by a Domain parent (`StockStatus`, `PurchaseOrderItem`). `.claude/rules/domain-class-placement.md`. Read this before Section A — it can override the redirect map when a Domain parent holds the candidate class.
- **Domain exception design** — static messages, dynamic data in readonly properties, returned from `context()` for Sentry grouping. `.claude/rules/domain-exceptions.md` (auto-loads on `app/Domain/**/Exceptions/**/*.php`).
- **Domain validator patterns** — when to extract assertions into a `Validators/` subdirectory. `.claude/rules/domain-validators.md` (auto-loads on `app/Domain/**/Validators/*.php`).
