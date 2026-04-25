---
paths:
  - "app/Infrastructure/**/Responses/**/*Response.php"
---

# Infrastructure — Response DTO Rules

## Class Shape

- DO declare the class `final` (not `final readonly`) and extend `Spatie\LaravelData\Data`. Use per-property `public readonly`, not class-level readonly.
- DO implement `App\Infrastructure\Contracts\DomainConvertibleInterface` with a `toDomain(): {DomainVO}` method — the `*ResponseParserTrait` methods call `$dto->toDomain()` on every parsed item; omitting it breaks the parser silently.
- DO use class-level `#[MapInputName(...)]` with a mapper appropriate for the third party's wire shape — `Spatie\LaravelData\Mappers\SnakeCaseMapper` for snake_case APIs, custom mappers like `App\Infrastructure\Linnworks\Support\PascalCaseMapper` for PascalCase APIs. See neighbouring Response DTOs for the right choice.
- DO NOT reference this DTO from Domain code — Domain stays framework-independent. Translate to Domain value objects via `toDomain()` before crossing the layer boundary.
- DO let parse failures propagate from `::from(...)` — the calling `*ResponseParserTrait` is where they become `InvalidApiResponseException`.

Canonical: `Linnworks/Responses/OrderResponse`, `Shopwired/Responses/OrderResponse`.
