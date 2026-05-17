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
- DO NOT use `BooleanType` on query-string boolean filters — query params arrive as raw strings, so Spatie's cast fails before validation runs. Use `public readonly ?string $flag = null` with `#[Nullable, StringType]` + `'in:true,false,1,0'` in `rules()` + a static `parseBoolFilter()` to resolve to `?bool`. EXCEPTION: `BooleanType` + `?bool` is correct for POST/JSON body properties (JSON sends typed booleans). Canonical: `ListContactSubmissionsRequestDTO`.

## Item DTOs

- DO expose a `toCommand(): {Domain}Command` method on DTOs that map 1:1 to a Domain command. Hold wire types on the DTO (string, int, float, nullable scalars); construct value objects / enums / Money inside `toCommand()`. Canonical: `CostPriceItemDTO::toCommand()`.
- DO follow the merge-patch shape (two-map split + field enum) for partial-update DTOs whose properties are `Optional|T|null` — see `.claude/rules/application-commands.md`.
