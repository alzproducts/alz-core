---
paths:
  - "app/Presentation/Http/**/DTOs/**/*RequestDTO.php"
---

# Presentation — Request DTO Rules

## Class Shape

- DO extend `Spatie\LaravelData\Data` and declare the class `final` (not `final readonly` — codebase convention is per-property `public readonly`).
- DO mark properties `public readonly` with defaults where the endpoint accepts omission.

## Validation

- DO use attribute-form validation on scalar properties: `#[IntegerType, Min(1), Max(1000)]`.
- DO use `public static function rules()` for array-keyed payloads (`'fields' => [...]`, `'fields.title' => [...]`).
- DO use `RejectsUnknownFieldKeysTrait` + implement `allowedFieldKeys()` whenever accepting a closed-set `fields` map — prevents unexpected keys silently reaching the use case.
- DO use `ValidatesIncludesTrait` + implement `allowedIncludes()` for any endpoint accepting an `include=` query param; DO declare `public readonly ?string $include = null`.

## Item DTOs

- DO expose a `toCommand(): {Domain}Command` method on DTOs that map 1:1 to a Domain command. Hold wire types on the DTO (string, int, float, nullable scalars); construct value objects / enums / Money inside `toCommand()`. Canonical: `CostPriceItemDTO::toCommand()`.
