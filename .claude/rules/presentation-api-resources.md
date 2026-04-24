---
paths:
  - "app/Presentation/Http/Api/Resources/**/*Resource.php"
---

# Presentation — API Resource Rules

## Class Shape

- DO extend `Illuminate\Http\Resources\Json\JsonResource`, declare the class `final`, and add `@mixin {DomainValueObject}` to the class docblock so `$this->resource` autocompletes in `toArray()`.
- DO override with `#[Override] public function toArray(Request $request): array`.

## List / Detail Pair

- DO expose `public static function baseFields({VO} $vo): array` on the list resource (`{Entity}Resource`) and compose the detail resource (`{Entity}DetailResource`) as `baseFields(...) + conditionalIncludes(...) + ['meta' => ...]` — for top-level entities with both a list and a detail endpoint.
- EXCEPTION: nested / child resources used via `::collection(...)` from a parent resource are single-tier, no `baseFields()`, no detail variant. Canonical: `ProductVariationResource`.

## Conditional Includes

- DO guard optional fields with `$result->hasInclude({EntityInclude}::Foo)` — never inline null-checks; the include enum is the contract.
- DO wrap `{DetailResource}` around a Use Case Result when it carries both the domain object AND the requested includes list (`GetProductResult`); wrap it around the raw value object only when there are no conditional includes.

## Closures

- DO use `static fn(...): array` closures when mapping collections of value objects.
