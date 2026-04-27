---
paths:
  - "app/Infrastructure/**/Responses/**/*Response.php"
---

# Infrastructure — Response DTO Rules

## Class Shape

- DO declare the class `final` (not `final readonly`) and extend `Spatie\LaravelData\Data`. Use per-property `public readonly`, not class-level readonly.
- DO implement `App\Infrastructure\Contracts\DomainConvertibleInterface` with a `toDomain(): {DomainVO}` method when the response maps to a Domain value object — the `*ResponseParserTrait` methods call `$dto->toDomain()` on every parsed item; omitting it breaks the parser silently.
- DO implement `App\Infrastructure\Contracts\DtoConvertibleInterface` with a `toDto(): {ApplicationDTO}` method when the integration data is not a domain concept and the target lives in `App\Application\` (third-party data the Domain layer does not model). Mapping is invoked manually in the client, not via a parser trait.
- DO NOT use `toDomain()` to return an Application DTO — the method name lies about the target layer.
- DO use class-level `#[MapInputName(...)]` with a mapper appropriate for the third party's wire shape — `Spatie\LaravelData\Mappers\SnakeCaseMapper` for snake_case APIs, custom mappers like `App\Infrastructure\Linnworks\Support\PascalCaseMapper` for PascalCase APIs. See neighbouring Response DTOs for the right choice.
- DO NOT reference this DTO from Domain code — Domain stays framework-independent. Translate to Domain value objects via `toDomain()` before crossing the layer boundary.
- DO let parse failures propagate from `::from(...)` — the calling `*ResponseParserTrait` is where they become `InvalidApiResponseException`.

Canonical (Domain target): `Linnworks/Responses/OrderResponse`, `Shopwired/Responses/OrderResponse`.
Canonical (Application DTO target): `ClickUp/Responses/AuthenticatedClickUpUserResponse`, `ClickUp/Responses/TaskResponse`.
