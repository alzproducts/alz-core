---
paths:
  - "app/Presentation/Http/**/Controllers/**/*Controller.php"
---

# Presentation — Controller Rules

## Class Shape

- DO default to `final readonly class` with constructor-promoted `private` use-case dependencies; drop `readonly` when there are no injected dependencies. Canonical with-DI: `BrandController`. Canonical no-DI: `QueueHealthController`.
- DO inject concrete use-case / service classes, never generic interfaces — use cases are the contract boundary.
- DO delegate any work that reaches Domain, Infrastructure, or an external API to an Application use case. EXCEPTION: pure framework-facade operations with no business logic (queue depth, signed URL redirect). Canonical: `QueueHealthController`, `FeedController`.
- **Splitting:** DO keep one `{Feature}Controller` per feature for slim CRUD. DO split into `{Feature}Controller` (reads) + `{Feature}UpdateController` (writes) when writes pull in external-API exception surface — keeps each controller's `@throws` tractable. Canonical: `BrandController` + `BrandUpdateController`.
- **Invokable:** DO use `__invoke(...)` for single-action entry points. The parameter may be `Request`, a request DTO, or any container-resolvable type — Laravel resolves the signature from the container and route binding. Canonical: `ContactFormController::__invoke(Request)`, `ProfileController::__invoke(AuthenticatedUser)`.

## Exception Handling

- DO NOT add try/catch — `bootstrap/app.php` maps domain exceptions to HTTP responses globally. EXCEPTION: transaction rollback + redirect that the handler can't replicate.
- DO list every domain exception the use case can raise in `@throws` on both the class docblock and each method — no linter infers this from use-case `@throws`.

## Request / Response

- DO use a `*RequestDTO` whenever the action reads body or query input that needs parsing, validation, or coercion. The `Request` parameter is permitted only for request-meta (headers, IP, signed-URL inspection), never for reading user input. Canonical: `SaveClickUpApiKeyRequestDTO` paired with `ClickUpAuthController::save`.
- DO convert wire types to value objects at the boundary: `IntId::from($productId)` for typed route params; VO construction belongs inside a DTO's `toCommand()`. The use case receives domain types, never raw scalars.
- DO return `new JsonResponse(null, Response::HTTP_NO_CONTENT)` for successful writes with no body.
- DO wrap a single domain result in `{Entity}DetailResource`; paginated lists via `$this->paginatedResponse($result, {Entity}Resource::class)` from `BuildsPaginatedResponseTrait`.
